# Pretty Links Dynamic Variables — Plain-English Guide

This guide explains, in everyday language, what this plugin does, why it's useful,
and how to use it. No technical background needed.

---

## The one-sentence version

Every time someone clicks one of your affiliate links, this plugin quietly records
**who clicked, which page they clicked from, and where in the list the link was** —
and it tags the click with a secret code so that when the casino or betting site
later reports a sign-up, you can trace it back to the exact spot on your website
that earned it.

---

## Why you'd want this

You run pages like "Best Crypto Casinos" with a ranked list of brands, each with a
"Visit Website" button. Those buttons are affiliate links. Today, when someone
clicks and signs up, the affiliate network tells you "you got a sign-up" — but not
**which page**, **which position in the list**, or **which visit** it came from.

This plugin fills that gap. It lets you answer questions like:

- Which of my pages actually drive sign-ups, not just clicks?
- Does the #1 spot in the list really convert better than #3?
- Which brand earns the most from which page?

---

## The three things it captures on every click

1. **A unique click code** — a fresh, one-of-a-kind ID for that single click.
2. **The page** — e.g. `best-crypto-casinos`.
3. **The position in the list** — the rank the visitor actually saw (important,
   because the list reorders itself for different countries).

It also grabs a few bonus details automatically: the brand name (e.g. "Thrill"),
where the button was (a comparison table vs. inline in an article), and the kind of
page it was on.

---

## What "encrypted" means here, and why it matters

The click code is **scrambled into a secret token** before it's attached to the
affiliate link. Think of it as a sealed envelope:

- The affiliate network receives the sealed envelope and passes it back to you
  later, in their reports, when a sign-up happens.
- **Only your website holds the key** to open it. The network — and anyone snooping
  on the link — can't read what's inside.

When you open the envelope back on your side, it points to the exact click in your
records, with its page and position. That's how a sign-up gets traced back to the
right spot.

> If, for any reason, the secret key isn't set up, the plugin will warn you loudly
> in the admin area rather than quietly sending unscrambled codes.

---

## Nothing gets lost

Even if a particular link hasn't been matched to an affiliate platform yet, the
click is **still recorded** — it's just flagged as "needs setup." So you never lose
data while you finish configuring things. The dashboard shows you exactly which
links still need attention.

---

## Using it — the four screens

In your WordPress admin sidebar you'll find **Pretty Links DV**, with four tabs.

### 1. Reports
Your dashboard. See total clicks, and break them down by page, by list position, by
brand, and by affiliate platform. A **coverage** section highlights any links that
aren't fully set up yet. You can **export everything to a spreadsheet (CSV)** to
match against the networks' own sign-up reports.

### 2. Settings
Turn things on and off in plain controls — no code:
- Record every link click, or only the affiliate ones.
- Turn the encryption on/off and see whether your secret key is in place.
- Choose how visitor IP addresses are handled (off, anonymised, or full) — set to
  **anonymised** by default, which is privacy-friendly for EU visitors.
- How long to keep records.

### 3. Mappings
Each affiliate platform (Income Access, ReferOn, Smartico, and ~20 more) expects
the tracking code in a differently-named slot. This screen holds the correct slot
for each one, pre-filled from a master reference sheet. You can adjust any of them.
Some platforms have quirks the plugin already knows about (for example, one only
accepts numbers, so the plugin automatically uses a number-friendly code there).

### 4. Test
The safety net. Pick one of your links, fill in a pretend page and position, and
hit **Run test**. It shows you *exactly* what would happen — the final link the
visitor would be sent to, the sealed token, proof that it unseals correctly, and
the record that would be saved — **without actually sending anyone anywhere or
saving anything**. Use it to confirm a new platform is set up right before real
traffic hits it.

---

## How to set it up (once)

1. Make sure the **Pretty Links** plugin is installed and active.
2. Activate **Pretty Links Dynamic Variables**.
3. (Recommended) Add a secret key to your site's `wp-config.php` file so the key
   lives outside the database. Your developer can do this in two minutes; if you
   skip it, the plugin makes one for you automatically.
4. Edit any Pretty Link and pick its **affiliate software** from the dropdown box.
5. Open the **Test** tab and run a test on that link to confirm it looks right.
6. Watch the **Reports** tab fill up as real clicks come in.

That's it. From then on it works in the background on every click.

---

## What it deliberately does NOT do

- It doesn't slow down your normal pages — it only does work when an actual
  affiliate link is clicked.
- It doesn't replace or interfere with Pretty Links' own click counts — both keep
  working side by side.
- It doesn't send any private information to the affiliate networks — only the
  sealed, meaningless-to-them token.

---

## A note on what's still to come

The plugin records clicks and seals the tracking token today. The final piece —
automatically **matching the networks' sign-up reports back to your clicks** — is
planned as a later add-on. Until then, you reconcile by exporting your CSV and
lining it up against each network's report (the sealed token appears in both).
