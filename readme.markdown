# tumblelog.php
## host your own damn tumblelog

Here's an early version of a self-hosted tumblr-like tumblelog.

- Multiple post types
- Single-user only
- Files stored in plain text (Yaml)
- Doesn't need to be at root (e.g. yourdomain.com/tumblelog)
- Custom post slugs (e.g. yourdomain.com/tumblelog/neat-new-post)
- Probably lots of security vulnerabilities

Pull requests very welcome. Let's make 'em sweat.

## Installation

- Copy this repository into a directory on the world wide web. It needs PHP, but not necessarily a very recent version.
- Navigate to the directory in your world wide web browser.
- Fill out the fields (Site name, description, your email address, and a password).
- You'll be redirected to the front page.
- Navigate to /login to log in.
- Use the user remote to edit your settings or create, edit, and delete posts after you've logged in.
- Edit your theme files and stylesheets via FTP.
- Drop any file into /public to have it pass through unscathed (e.g. the file located at /tumblelog/public/base.js would be accessible on the world wide web at /tumblelog/base.js)
- Eat your vegetables.