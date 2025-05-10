# mail-summary-stalwart
This is a simple PHP script that automatically summarizes all incoming emails and prepends the summary to the email content.
The script utilizes the MTA-Hook feature provided by Stalwart Mail Servers.

## How does it work?
Stalwart Mail Server provides a handy tool, MTA-Hook, that enables the processing of email headers and contents through simple HTTP protocol.  With this, we can parse the incoming email content, send it to AI for a summary, then prepend the summary to the content and return it to the mail server.

## What does the summary look like?
Below is a screenshot of what it looks like.  You can customize the codes and the prompt however you like to fit your needs.

## How to use it?
Put the .php file behind a webserver, fill in your API key, plug in the URL into Stalwart MTA-Hooks settings, and enjoy.
Make sure the hook is select to run on the `DATA` stage.

Cheers.
