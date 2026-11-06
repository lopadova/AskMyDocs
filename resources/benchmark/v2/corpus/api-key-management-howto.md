# API key management how-to

How customers and internal teams manage production API keys.

## Creating a key

Open the developer portal, choose Keys and press Create. Give the key a
name that identifies the integration. Copy it immediately: it is shown once.

## Validity

Keys expire after 180 days. The portal sends a reminder email 30 days
before the expiry date.

## How to rotate

Run `nwctl keys rotate --name <key-name>` or use the Rotate button in the
portal. The new key is active immediately and the previous key keeps working
for another 24 hours, so deployments can switch without downtime.

## Revoking

Revoke a key from the portal when it leaks. Revocation is immediate and
cannot be undone; create a new key instead.

## Storage

Keep keys in the secret manager of your platform. Never commit them to
source control and never send them by email or chat.
