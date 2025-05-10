# mail-summary-stalwart
This is a simple PHP script that automatically summarizes all incoming emails and prepends the summary to the email content.
The script utilizes the MTA-Hook feature provided by [Stalwart Mail Servers](https://github.com/stalwartlabs/mail-server).

## How does it work?
Stalwart Mail Server provides a handy tool, MTA-Hook, that enables the processing of email headers and contents through simple HTTP protocol.  With this, we can parse the incoming email content, send it to AI for a summary, then prepend the summary to the content and return it to the mail server.

## What does the summary look like?
Below is a screenshot of what it looks like.  You can customize the codes and the prompt however you like to fit your needs.

<img src="https://raw.githubusercontent.com/Har-Kuun/mail-summary-stalwart/refs/heads/main/screenshots/example_summary.png" width="500"/>

## How to use it?
Put the .php file behind a webserver, fill in your API key, plug in the URL into Stalwart MTA-Hooks settings, and enjoy.
Make sure the hook is selected to run on the `DATA` stage.

<img src="https://raw.githubusercontent.com/Har-Kuun/mail-summary-stalwart/refs/heads/main/screenshots/stalwart-mta-hooks-settings.png" width="500"/>

## What model is used to summarize?
By default it uses `gpt-4o-mini` for higher speed and lower cost.  You can however change it to other models or providers easily.

### **Cheers.**
