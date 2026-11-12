# Trume Admin

Content management for the Trume Company Limited website.
Runs on its own subdomain and shares one SQLite database with the public site.

```
/home4/rmsugnxf/
├── trume-data/trume.sqlite                         shared database (outside public_html)
└── public_html/
    ├── trumecompanyltd.truehome.com.ug/            public website (reads the database)
    │   └── uploads/                                images uploaded from the admin
    └── trume-admin.truehome.com.ug/                this admin (writes the database)
```

## What it controls

| Admin section | Effect on the website |
|---|---|
| Pages | Main heading, introduction, Google/preview text of each page |
| Founders | Founder cards on the About page |
| Insights | Articles on the Insights page (drafts stay hidden) |
| Media | Image library used by founders and articles |
| Messages | Contact form and partnership enquiries |
| Site settings | Email, phones, address and links shown across the site; the email also receives form messages |
| Administrators | Team accounts (owner only) |

Formatting in text fields: `*words*` shows words in the lime highlight style, a blank line starts a new
paragraph, and a line starting with `## ` becomes a subheading.

## Local development

```bash
# Terminal 1 – public website
cd "/home/kainamura/StudioProjects/Trume company Ltd" && php -S localhost:8000
# Terminal 2 – admin
cd "/home/kainamura/StudioProjects/Trume- Admin" && php -S localhost:8001
```

Open http://localhost:8001/install.php once to create the first account (the setup key is in
`config.php`), then sign in at http://localhost:8001/login.php.

## Deploying to cPanel

1. **Public website**: upload it to `public_html/trumecompanyltd.truehome.com.ug`, then create its
   `config.php` from `config.example.php` (the example already has the server paths).
2. **Admin**: upload this folder's files to `public_html/trume-admin.truehome.com.ug`
   (not `README.md`). Create `config.php` from `config.example.php` and replace `setup_key`
   with a long random value.
3. Visit `https://trume-admin.truehome.com.ug/install.php`, enter the setup key and create the owner
   account. This creates `/home4/rmsugnxf/trume-data/trume.sqlite` and fills it with the website's
   current content.
4. **Delete `install.php`** from the server in File Manager.
5. In cPanel → Domains, turn on **Force HTTPS Redirect** for the admin subdomain.
6. Optional extra lock: cPanel → **Directory Privacy** on the admin folder adds a second password.

## Security notes

- The database lives outside `public_html` because this host does not apply `.htaccess` rules,
  so files inside a website folder can be downloaded.
- Passwords are hashed; sessions are regenerated on sign-in; every form has CSRF protection.
- Five failed sign-ins from one connection lock it out for 15 minutes.
- Uploads are checked to be real JPG/PNG/WebP/GIF images and renamed randomly.
- Back up `trume-data/trume.sqlite` (download it from File Manager) before big changes.
