=== FB Posts Feed ===
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Shows the latest posts from your Facebook Page as a responsive gallery. Each card opens the post on Facebook.

== Installation ==

1. WordPress admin → Plugins → Add New → Upload Plugin → choose gd-fb-feed.zip → Install → Activate.
2. Settings → Facebook Feed → follow "Connect your Facebook Page" (one-time, ~5 minutes).
3. Add the shortcode [fb_posts] to any page (Gutenberg: add a "Shortcode" block).

Before you connect, the plugin shows Facebook's own embed widget, so the page is never empty.

== Shortcode options ==

[fb_posts limit="9" columns="3" excerpt="200" images="yes" header="yes" follow="yes" layout="grid" mode="api"]

limit    – number of posts (1–50)
columns  – columns on desktop (1–4); tablets show 2, phones 1
excerpt  – max characters of text (0 = full text)
images   – yes / no
header   – show Page name, picture and date: yes / no
follow   – show "Follow us" button: yes / no
layout   – grid (gallery) or list (single column)
mode     – api (gallery) or embed (Facebook widget)

== How it works ==

* Posts are loaded from the Facebook Graph API with a permanent Page access token.
* Results are cached (default 60 minutes). Old posts are shown instantly while new ones load in the background, so the page is never slowed down by Facebook.
* If Facebook is unreachable, the last good copy is shown. If nothing was ever loaded, visitors see the Facebook embed instead.
* Posts with an image show image + text. Posts without an image show a text-only card.

== Styling ==

Override CSS variables in your theme (Appearance → Customize → Additional CSS):

.gdff {
  --gdff-accent: #e4572e;
  --gdff-radius: 8px;
  --gdff-gap: 20px;
  --gdff-image-ratio: 1 / 1;   /* square images */
}
