# Serving the WebP versions with Nginx

JCORE Pakkaus saves a WebP version next to every JPEG and PNG file it optimizes, with `.webp` appended
to the full file name:

```
wp-content/uploads/2026/10/photo.jpg              photo.jpg.webp
wp-content/uploads/2026/10/photo-300x200.jpg      photo-300x200.jpg.webp
wp-content/uploads/2026/10/logo.png               logo.png.webp
```

Pages keep linking to `photo.jpg`. Nginx picks the file per request from the browser's `Accept`
header: browsers that support WebP send `image/webp` in it and get the smaller file, everything else
gets the original. URLs never change, so the WebP versions work in post content, page builders, CSS
backgrounds and theme templates alike, and turning them off is just removing the rules.

Appending the extension (rather than swapping it) keeps `photo.jpg` and `photo.png` from colliding, and
makes the rule a plain "does `<file>.webp` exist?".

## The rules

In the `http` block, for example in `/etc/nginx/conf.d/webp.conf`:

```nginx
map $http_accept $webp_suffix {
    default        "";
    "~*image/webp" ".webp";
}
```

In the site's `server` block, **above** any other location that matches `.jpg` or `.png`:

```nginx
location ~* ^/wp-content/uploads/.+\.(?:jpe?g|png)$ {
    add_header Vary Accept;
    try_files $uri$webp_suffix $uri =404;
}
```

Then `nginx -t && nginx -s reload` (or `systemctl reload nginx`).

For a request to `photo.jpg` with `Accept: image/webp`, `try_files` first tries `photo.jpg.webp` and
falls back to `photo.jpg` when there is no WebP version. Nginx takes the content type from the file it
serves, so the WebP goes out as `image/webp`.

## Things to get right

- **Order.** Nginx uses the first regular-expression location that matches, in the order they appear
  in the file. Most WordPress configurations have something like
  `location ~* \.(?:jpg|jpeg|gif|png|webp|svg)$ { expires 1y; }`. Put the uploads location above it, or
  requests never reach it.
- **Headers.** An `add_header` in a location drops all the `add_header`s it would otherwise inherit from
  the `server` block, so repeat the security headers you rely on inside the new location. And as only
  one location handles a request, the image location further down (with its `expires`, say) no longer
  applies to uploads; copy its caching settings too:

  ```nginx
  location ~* ^/wp-content/uploads/.+\.(?:jpe?g|png)$ {
      expires 1y;
      access_log off;
      add_header Vary Accept;
      try_files $uri$webp_suffix $uri =404;
  }
  ```

- **`Vary: Accept`** tells every cache along the way (the browser's, proxies, CDNs) that the response
  depends on the `Accept` header. Without it, a shared cache can store the WebP and hand it to a client
  that can't show it. If a CDN or caching proxy in front of the site ignores `Vary` for images, either
  set it to vary images on `Accept`, or leave `/wp-content/uploads/` out of its cache.
- **Other upload paths.** Multisite (`/wp-content/uploads/sites/2/…`) is covered. Bedrock serves uploads
  from `/app/uploads/`; change the path in the location to match. When uploads are offloaded to object
  storage, the WebP versions stay on the server and these rules don't apply.
- **MIME type.** The `mime.types` file that ships with Nginx maps `webp` to `image/webp`. Check a custom
  one with `grep webp /etc/nginx/mime.types`.

## Check it

In WordPress: **Settings → JCORE Pakkaus → Settings → WebP delivery → Check delivery**, or
`wp pakkaus serving`. It requests an optimized image twice, as a browser with and without WebP support,
and reports what came back. The site has to be able to reach its own URL, as for Site Health's loopback
test.

By hand, with an image that has a `.webp` version:

```sh
# WebP for browsers that accept it…
curl -sI -H 'Accept: image/avif,image/webp,*/*' https://example.com/wp-content/uploads/2026/10/photo.jpg | grep -iE 'content-type|vary'
# …the original for the rest.
curl -sI -H 'Accept: image/png,image/*;q=0.8' https://example.com/wp-content/uploads/2026/10/photo.jpg | grep -iE 'content-type|vary'
```

Expect `image/webp`, then `image/jpeg`, both with `Vary: Accept`.

## Turning it off

Remove the location (and the map). The `.webp` files are harmless where they are. To delete them, use
**Restore original** on an image, or `wp pakkaus restore <id>…`. That also puts back kept original PNG
files.

## Later: AVIF

The plugin and service are set up for more formats next to the files (`photo.jpg.avif`), but only WebP
is made today. When AVIF is added, the map gains an entry and `try_files` tries it first:

```nginx
map $http_accept $avif_suffix {
    default        "";
    "~*image/avif" ".avif";
}

location ~* ^/wp-content/uploads/.+\.(?:jpe?g|png)$ {
    add_header Vary Accept;
    try_files $uri$avif_suffix $uri$webp_suffix $uri =404;
}
```
