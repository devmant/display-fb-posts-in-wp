# Display Facebook Posts in WordPress

A WordPress plugin that automatically shows the latest posts from a Facebook Page as a responsive gallery. Each card shows the image and text (text only when a post has no image) and opens the post on Facebook when clicked.

## Features

- Gallery grid: 3 columns on desktop, 2 on tablets, 1 on phones (columns configurable)
- Image + text cards; text-only cards for posts without an image
- Video posts get a play icon, albums show a "+N" badge
- Whole card is clickable and opens the post (new tab by default)
- Posts refresh automatically in the background (default every 60 minutes), so pages never wait for Facebook
- Falls back to Facebook's own embed widget if the connection isn't set up or Facebook is unreachable
- One-click connection helper that creates a non-expiring Page access token (App Secret is never stored)
- Lithuanian defaults for button texts when the site language is Lithuanian

## Installation

1. Download `gd-fb-feed.zip` from [Releases](../../releases).
2. In WordPress: **Plugins → Add New → Upload Plugin** → choose the zip → **Install** → **Activate**.
3. Go to **Settings → Facebook Feed** and follow **Connect your Facebook Page** (one-time, about 5 minutes).
4. Add the shortcode `[fb_posts]` to any page (in the block editor, use a **Shortcode** block).

## Shortcode options

```
[fb_posts limit="9" columns="3" excerpt="200" images="yes" header="yes" follow="yes" layout="grid" mode="api"]
```

| Option    | Description                                         |
|-----------|-----------------------------------------------------|
| `limit`   | Number of posts (1–50)                              |
| `columns` | Columns on desktop (1–4)                            |
| `excerpt` | Max characters of text (0 = full text)              |
| `images`  | Show images: `yes` / `no`                           |
| `header`  | Show Page name, picture and date: `yes` / `no`      |
| `follow`  | Show "Follow us" button: `yes` / `no`               |
| `layout`  | `grid` (gallery) or `list` (single column)          |
| `mode`    | `api` (gallery) or `embed` (Facebook widget)        |

## Styling

Override CSS variables in your theme (**Appearance → Customize → Additional CSS**):

```css
.gdff {
  --gdff-accent: #e4572e;
  --gdff-radius: 8px;
  --gdff-gap: 20px;
  --gdff-image-ratio: 1 / 1; /* square images */
}
```

## Requirements

- WordPress 5.8+, PHP 7.4+
- A Facebook Page (business/creator). Personal profiles can't be read by the Facebook API.

## License

GPL-2.0-or-later
