# Twitch GPT Endpoint

Provides an endpoint for Nightbot or other twitch bots to consume via fetch.

# Pre-requisites

- PHP 8.2
- Composer
- Hosting service
- Domain name

# Secrets

We are using the .env file outside of the web root as a security paradigm, so your secrets are contained within the .env file and are not web accessible.

To get started, copy `.env.example` to `.env`, and change the values.

`OPENAI_API_KEY` 

Should be your OpenAI API Key provided by OpenAI here:

`OPENAI_PROMPT_CONTEXT_URL`

Should be the RAW URL to your github txt file containing your context, this URL usually starts with `https://raw.githubusercontent.com`, and can be found by clicking the "Raw" button (top right) on your file.

# Hosting

You will need to host this on a hosting service so that it's accessible from a domain name. You will also need to point a domain name at your hosting service.

# Setup

git clone the repository to your hosting service:

`git clone https://github.com/MadMikeyB/twitch-openai-api.git .`

Run composer install

`composer install`

Run the steps in <a href="#secrets">Secrets</a> above to change .env.example to .env

Point your hosting services "public directory" to "public/".


## Sending Twitch Chat Input to an Endpoint Using Nightbot

To send input from Twitch chats to your endpoint using Nightbot's URL fetch feature, follow these steps:

### Step 1: Log in to Nightbot

Go to [Nightbot](https://nightbot.tv/) and log in with your Twitch account.

### Step 2: Add a Custom Command

1. Go to the `Commands` section on the Nightbot dashboard.
2. Click on `+ Add Command`.

### Step 3: Configure the Command

- **Command**: Set the command trigger word. For example, if you want to trigger the endpoint with `!nightbot`, type `!nightbot` here.
- **Message**: Use the URL fetch feature to send a request to your endpoint. The format will be:

```
$(urlfetch https://example.com/index.php?user=$(user)&prompt=$(querystring))
```

Here’s an example configuration:

- **Command**: `!nightbot`
- **Message**: `$(urlfetch https://example.com/index.php?user=$(user)&prompt=$(querystring))`

### Step 4: Save the Command

Click the `Submit` button to save your new command.

### Example Usage

Now, when someone types `!nightbot <message>` in your Twitch chat, Nightbot will send a request to `https://example.com/index.php` with the Twitch username and the message.

For example, if a user named `TwitchUser123` types:

```
!nightbot Hello, this is a test message!
```

Nightbot will send a request to:

```
https://example.com/index.php?user=TwitchUser123&prompt=Hello,%20this%20is%20a%20test%20message!
```

### Important Points

- Ensure that your endpoint `https://example.com/index.php` can handle GET requests and processes the `user` and `prompt` parameters correctly.
- Consider URL encoding special characters in the message to ensure the URL is correctly formatted.
- You may want to add error handling on your server to manage potential issues with malformed requests.

This setup allows you to dynamically send Twitch chat messages to your endpoint using Nightbot.