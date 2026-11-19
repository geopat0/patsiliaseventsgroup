"""Build the PHP hosting ZIP from the site's static source and pinned PHPMailer."""

import argparse
import hashlib
import io
import json
from pathlib import Path
import re
import tarfile
from urllib.parse import urlsplit
from urllib.request import urlopen
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo


HOSTING = Path(__file__).resolve().parent
SITE = HOSTING.parent
PAGES_URL = "https://geopat0.github.io/patsiliaseventsgroup/"
MAILER_VERSION = "7.2.0"
MAILER_URL = f"https://codeload.github.com/PHPMailer/PHPMailer/tar.gz/refs/tags/v{MAILER_VERSION}"
MAILER_SHA256 = "4a28b475899726851241a0f95069bc6c7af957555f2964fea1e87979023557e6"
MAILER_FILES = ("LICENSE", "src/Exception.php", "src/PHPMailer.php", "src/SMTP.php")

STATIC_FILES = (
    "index.html", "sound_equipment.html", "lighting_equipment.html",
    "patsilias_events_bootstrap.html", "styles.css", "script.js",
    "patsilias-events-logo.svg", "favicon.svg", "eon615_duo_z_large.jpg",
    "hk-audio-linear3-112xa-front-detail.jpg", "mrx518s.jpg",
    "stageworx-lb3-lighting-stand.jpg", "stairville-clb5-led-bar.jpg",
    "tmix-xmix-1402.jpg", "varytec-hero-spot-wash-140.jpg",
    "varytec-hero-wash-340fx.jpg",
)


def replace_once(content, old, new):
    if content.count(old) != 1:
        raise ValueError(f"Expected exactly one copy of {old!r} in the site source")
    return content.replace(old, new, 1)


def configure_form(html):
    html = replace_once(
        html,
        'action="mailto:geopcbl@gmail.com" method="post" enctype="text/plain"',
        'action="contact.php" method="post" data-host-contact',
    )
    html = replace_once(html, "Δημιουργία αιτήματος", "Αποστολή αιτήματος")
    html = replace_once(
        html,
        '<p class="form-note">Θα ανοίξει η εφαρμογή email σας με συμπληρωμένο το αίτημα.</p>',
        '<p class="form-note">Χρησιμοποιούμε τα στοιχεία σας για να απαντήσουμε στο αίτημά σας.</p>\n'
        '        <p class="contact-status" role="status" aria-atomic="true" data-contact-status></p>',
    )
    equipment = '<input type="hidden" name="equipment" data-equipment-field>'
    html = replace_once(
        html, equipment,
        equipment + '\n        <div hidden><label>Ιστότοπος'
        '<input type="text" name="website" autocomplete="off" tabindex="-1"></label></div>',
    )
    html = replace_once(
        html, "</head>",
        '  <link rel="stylesheet" href="contact-form.css">\n'
        '  <noscript><style>.reveal{opacity:1;transform:none}</style></noscript>\n</head>',
    )
    return replace_once(html, "</body>", '  <script src="contact-form.js" defer></script>\n</body>')


def configure_metadata(html, site_url):
    if site_url:
        return html.replace(PAGES_URL, site_url)
    html = re.sub(r'<link rel="canonical" href="[^"]+">', "", html)
    html = re.sub(r'<meta property="og:url" content="[^"]+">', "", html)
    html = html.replace(f'"url":{json.dumps(PAGES_URL)},', "")
    if PAGES_URL in html:
        raise ValueError("Hosted HTML still contains the GitHub Pages base URL")
    return html


def mailer_sources(local_archive):
    if local_archive:
        compressed = local_archive.read_bytes()
    else:
        with urlopen(MAILER_URL, timeout=30) as response:
            compressed = response.read()
    if hashlib.sha256(compressed).hexdigest() != MAILER_SHA256:
        raise ValueError("PHPMailer archive does not match the pinned SHA-256")
    with tarfile.open(fileobj=io.BytesIO(compressed), mode="r:gz") as archive:
        return {
            name: archive.extractfile(f"PHPMailer-{MAILER_VERSION}/{name}").read()
            for name in MAILER_FILES
        }


def build(output, site_url, mailer):
    entries = {}
    for name in STATIC_FILES:
        if name.endswith(".html"):
            html = configure_metadata((SITE / name).read_text(encoding="utf-8"), site_url)
            entries[f"public_html/{name}"] = configure_form(html) if name == "index.html" else html
        else:
            entries[f"public_html/{name}"] = (SITE / name).read_bytes()

    for name in ("contact.php", "contact-form.js", "contact-form.css"):
        entries[f"public_html/{name}"] = (HOSTING / "public" / name).read_bytes()

    robots = "User-agent: *\nAllow: /\n"
    if site_url:
        robots += f"\nSitemap: {site_url}sitemap.xml\n"
        entries["public_html/sitemap.xml"] = (
            (SITE / "sitemap.xml").read_text(encoding="utf-8").replace(PAGES_URL, site_url)
        )
    entries["public_html/robots.txt"] = robots

    entries["patsilias-private/contact-config.example.php"] = (
        (HOSTING / "private/contact-config.example.php").read_bytes()
    )
    for name, data in mailer_sources(mailer).items():
        entries[f"patsilias-private/phpmailer/{name}"] = data
    entries["UPLOAD-README.txt"] = (HOSTING / "UPLOAD-README.txt").read_bytes()

    with ZipFile(output, mode="x", compression=ZIP_DEFLATED) as bundle:
        for path, content in entries.items():
            entry = ZipInfo(path)
            permissions = 0o600 if path.startswith("patsilias-private/") else 0o644
            entry.external_attr = (0o100000 | permissions) << 16
            bundle.writestr(entry, content, compress_type=ZIP_DEFLATED)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", required=True, type=Path, help="New ZIP to create")
    parser.add_argument("--site-url", help="Final HTTPS base URL for canonical URLs and sitemap")
    parser.add_argument("--phpmailer-archive", type=Path, help="Use a local copy of the pinned upstream archive")
    args = parser.parse_args()
    site_url = args.site_url
    if site_url:
        url = urlsplit(site_url)
        if (url.scheme != "https" or not url.hostname or url.username or url.password
                or url.query or url.fragment or not re.fullmatch(r"https://[A-Za-z0-9.\-:/_~]+", site_url)):
            parser.error("--site-url must be an absolute HTTPS site URL without credentials, query or fragment")
        site_url = site_url.rstrip("/") + "/"
    build(args.output, site_url, args.phpmailer_archive)


if __name__ == "__main__":
    main()
