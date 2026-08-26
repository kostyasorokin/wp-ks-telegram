# Security policy

## Reporting a vulnerability

Please report security issues privately, not in a public issue.

- Email: **mailbox@konstantinsorokin.com**
- Or use GitHub's [private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability) on [this repository](https://github.com/kostyasorokin/wp-ks-telegram/security/advisories/new).

Please include the plugin version, the WordPress and PHP versions, and enough
detail to reproduce. If you have a proof of concept, say what it demonstrates —
an unauthenticated visitor reaching something, a subscriber escalating, an
administrator being impersonated — because that is what decides how urgent it
is.

You will get an acknowledgement within a few days. There is no bounty; this is a
plugin written by one person and given away.

## Supported versions

1.0.0 is the first public release. There are no earlier versions to advise
anyone about, and no known unpatched issues.

## Things that are dangerous by design

Some settings do exactly what they say and the risk is the point. They are all
off by default:

- **Include plain passwords in authorization alerts** sends the submitted
  password to Telegram in clear text, including for failed logins. That means
  real passwords belonging to real people, sometimes for accounts on other
  sites. Enable it only if you understand and accept that.
- **Let Site Health test the reCAPTCHA key against Google** sends Contact Form 7's
  reCAPTCHA secret key to Google in order to check whether it is still valid.

Neither is a vulnerability, and both are documented in `readme.txt` under
External services.
