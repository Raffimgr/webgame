/* ridge-media: the Cloudflare Worker that keeps the trip feed's videos in Raffi's R2 bucket "ridge-media".
   It has no passwords of its own. Every upload carries a ticket and every view carries a viewing token, both handed out by
   social.php to logged-in players; the Worker asks social.php (CHECK_URL) whether they are good and remembers the answer for a while.

   GET  /ping                                   -> "ok" (the feed uses it to see whether video sharing is switched on)
   GET  /ping?deep=1                            -> "ok" only when social.php answers too; the result is also saved as _status.json in the bucket
   GET  /f/<key>?v=<view token>[&dl=<name>]      -> the video (range requests work, so players can seek); dl= makes it a download.
                                                   The feed's own pages may also fetch it, to hand it to the iPhone share sheet (Save Video).
   PUT  /up/<key>?t=<ticket>                     -> stores a video up to 90 MB in one go
   POST /mp/<key>?t=<ticket>&action=create&type= -> starts a big upload in parts: {uploadId}
   PUT  /mp/<key>?t=<ticket>&uploadId=&part=N    -> one part (up to 90 MB): {partNumber, etag}
   POST /mp/<key>?t=<ticket>&uploadId=&action=complete with {parts:[...]} -> finishes it
   DELETE /mp/<key>?t=<ticket>&uploadId=        -> gives up on a big upload
   DELETE /f/<key>?t=<delete ticket>             -> removes a video after its post is deleted

   Bindings: BUCKET (R2 bucket ridge-media), CHECK_URL (https://raffaelco.com/DM/social.php; was /ridge-games/ before the move), ORIGINS (comma list of pages allowed to upload). */

const KEY_RE = /^v\/[a-f0-9]{24}\.[a-z0-9]{1,5}$/;
const TOKEN_RE = /^[a-f0-9]{32}$/;
const TYPE_RE = /^video\/[a-z0-9.+-]{1,40}$/i;
const seen = new Map(); // "check=..." or "view=..." -> {op, until}

async function verify(env, query) {
  const now = Date.now(), hit = seen.get(query);
  if (hit && hit.until > now) return hit.op;
  let op = "";
  try {
    const r = await fetch(env.CHECK_URL + "?" + query, { headers: { "User-Agent": "ridge-media-worker" } });
    if (r.ok) { const j = await r.json(); if (j.ok === 1) op = String(j.op || ""); }
  } catch (e) { /* treat as not allowed */ }
  if (seen.size > 2000) seen.clear();
  seen.set(query, { op, until: now + (op ? 600000 : 10000) });
  return op;
}

/* Can the Worker reach social.php? A made-up viewing token must come back as a JSON "no" (403). */
async function deepCheck(env) {
  let status = 0, type = "";
  try { const r = await fetch(env.CHECK_URL + "?view=" + "0".repeat(32), { headers: { "User-Agent": "ridge-media-worker" } }); status = r.status; type = r.headers.get("Content-Type") || ""; } catch (e) { type = String(e); }
  const ok = status === 403 && type.includes("json");
  await env.BUCKET.put("_status.json", JSON.stringify({ at: new Date().toISOString(), ok, status, type }), { httpMetadata: { contentType: "application/json" } });
  return ok;
}

function corsHeaders(env, req) {
  const origin = req.headers.get("Origin") || "";
  const ok = (env.ORIGINS || "").split(",").map(s => s.trim()).includes(origin);
  return ok ? { "Access-Control-Allow-Origin": origin, "Vary": "Origin", "Access-Control-Expose-Headers": "ETag, Content-Length, Content-Range" } : {};
}
const json = (data, status, extra) => new Response(JSON.stringify(data), { status: status || 200, headers: { "Content-Type": "application/json", "Cache-Control": "no-store", ...extra } });

export default {
  async fetch(req, env) {
    const url = new URL(req.url), path = url.pathname, method = req.method, cors = corsHeaders(env, req);
    const fail = (msg, status) => json({ error: msg }, status, cors);

    if (method === "OPTIONS") return new Response(null, { status: 204, headers: { ...cors, "Access-Control-Allow-Methods": "GET, HEAD, PUT, POST, DELETE", "Access-Control-Allow-Headers": "Content-Type", "Access-Control-Max-Age": "86400" } });
    if (path === "/ping") {
      const ok = !url.searchParams.has("deep") || await deepCheck(env);
      return new Response(ok ? "ok" : "social.php can't be reached", { status: ok ? 200 : 503, headers: { ...cors, "Cache-Control": "no-store" } });
    }

    const m = /^\/(f|up|mp)\/(.+)$/.exec(path);
    if (!m || !KEY_RE.test(m[2])) return fail("Not found.", 404);
    const [, kind, key] = m;
    const ticket = url.searchParams.get("t") || "";
    const needOp = async op => TOKEN_RE.test(ticket) && (await verify(env, "check=" + ticket + "&key=" + encodeURIComponent(key))) === op;

    if (kind === "f" && (method === "GET" || method === "HEAD")) {
      const v = url.searchParams.get("v") || "";
      if (!TOKEN_RE.test(v) || (await verify(env, "view=" + v)) !== "view") return fail("Please open this from the trip feed.", 403);
      const obj = method === "HEAD" ? await env.BUCKET.head(key) : await env.BUCKET.get(key, { range: req.headers, onlyIf: req.headers });
      if (obj === null) return fail("Not found.", 404);
      const h = new Headers(cors);
      h.set("Vary", "Origin"); // the copy a <video> player got (no Origin) must not be reused for the feed's own fetch, or the other way round
      obj.writeHttpMetadata(h);
      h.set("ETag", obj.httpEtag);
      h.set("Accept-Ranges", "bytes");
      h.set("Cache-Control", "private, max-age=86400");
      h.set("X-Content-Type-Options", "nosniff");
      const dl = url.searchParams.get("dl");
      if (dl) h.set("Content-Disposition", 'attachment; filename="' + dl.replace(/[^A-Za-z0-9._-]/g, "_").slice(0, 100) + '"');
      if (method === "HEAD") { h.set("Content-Length", String(obj.size)); return new Response(null, { headers: h }); }
      if (!("body" in obj)) return new Response(null, { status: 304, headers: h });
      let status = 200, length = obj.size;
      if (obj.range && req.headers.has("Range")) {
        const r = obj.range;
        const offset = "suffix" in r ? obj.size - r.suffix : (r.offset || 0);
        length = "suffix" in r ? r.suffix : (r.length !== undefined ? r.length : obj.size - offset);
        status = 206;
        h.set("Content-Range", `bytes ${offset}-${offset + length - 1}/${obj.size}`);
      }
      h.set("Content-Length", String(length));
      return new Response(obj.body, { status, headers: h });
    }

    if (kind === "f" && method === "DELETE") {
      if (!(await needOp("del"))) return fail("Not allowed.", 403);
      await env.BUCKET.delete(key);
      return json({ ok: 1 }, 200, cors);
    }

    const type = url.searchParams.get("type") || req.headers.get("Content-Type") || "";
    const httpMetadata = { contentType: TYPE_RE.test(type) ? type : "application/octet-stream" };

    if (kind === "up" && method === "PUT") {
      if (!(await needOp("put"))) return fail("Upload not allowed. Try again from the trip feed.", 403);
      await env.BUCKET.put(key, req.body, { httpMetadata });
      return json({ ok: 1 }, 200, cors);
    }

    if (kind === "mp") {
      if (!(await needOp("put"))) return fail("Upload not allowed. Try again from the trip feed.", 403);
      const action = url.searchParams.get("action"), uploadId = url.searchParams.get("uploadId");
      if (method === "POST" && action === "create") {
        const up = await env.BUCKET.createMultipartUpload(key, { httpMetadata });
        return json({ uploadId: up.uploadId }, 200, cors);
      }
      if (!uploadId) return fail("Bad request.", 400);
      const up = env.BUCKET.resumeMultipartUpload(key, uploadId);
      if (method === "PUT") {
        const n = Number(url.searchParams.get("part"));
        if (!(n >= 1 && n <= 10000)) return fail("Bad request.", 400);
        const part = await up.uploadPart(n, req.body);
        return json({ partNumber: part.partNumber, etag: part.etag }, 200, cors);
      }
      if (method === "POST" && action === "complete") {
        const body = await req.json().catch(() => null);
        if (!body || !Array.isArray(body.parts)) return fail("Bad request.", 400);
        await up.complete(body.parts);
        return json({ ok: 1 }, 200, cors);
      }
      if (method === "DELETE") { await up.abort(); return json({ ok: 1 }, 200, cors); }
    }
    return fail("Not found.", 404);
  },
  async scheduled(event, env) { await deepCheck(env); }
};
