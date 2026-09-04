# Njiwa for PrestaShop

WhatsApp your customers when their order is paid, sent or cancelled, and get a
message yourself when one comes in.

## Install

1. Zip the `njiwa-prestashop` folder as **njiwa.zip**, with `njiwa.php` at the
   top of it, and upload it under **Modules → Module Manager → Upload a
   module**. Or copy the folder into `modules/` and rename it to `njiwa`.
2. Install **Njiwa WhatsApp**.
3. Press **Configure**.

PrestaShop 1.7 or 8.x, PHP 7.4 or newer. The server needs the PHP cURL
extension, which almost every shop already has; the module says so plainly if
it is missing.

The folder has to be called `njiwa`, because that is the module's name and
PrestaShop finds a module by its folder.

## Set it up

Paste your API key from [console.upeo.ai](https://console.upeo.ai) → API keys,
save, then press **Test connection**. It lists the WhatsApp numbers your Njiwa
account actually has, so you find out now rather than at the moment a customer
should have been messaged.

**Start with a test key.** A key beginning `sk_test_` checks and stores every
message and delivers nothing. Turn on the events you want, place a test order,
move it through your statuses, read the notes on the order, and only then swap
in the `sk_live_` key. A `sk_live_` key sends real WhatsApp messages to real
phones, and those cost money.

Then turn on the messages you want and edit the wording. Every field on the
page explains itself; the short version:

| Setting | What it is for |
| --- | --- |
| Send WhatsApp messages | The master switch. Off keeps every setting and sends nothing. |
| API key | `sk_test_` delivers nothing, `sk_live_` sends for real. |
| Njiwa address | Leave it alone unless you were given your own. |
| Send from | Which of your numbers sends. Empty means the account default. |
| Test message to | Only used by the Send test message button. Not saved. |
| Each message | On, off, and the exact wording. Empty wording sends nothing. |
| Your WhatsApp numbers | Where the new-order alert goes. Several, comma separated. |

Every message is off until you turn it on. Installing this module cannot cause
a message to be sent.

## What gets sent, and when

| When the order reaches | Who hears about it |
| --- | --- |
| Awaiting bank wire, cheque or cash-on-delivery payment | The customer: we have your order, waiting for payment |
| Payment accepted, or remote payment accepted | The customer: payment received, getting it ready |
| Shipped | The customer: it is on its way |
| Cancelled | The customer: cancelled, and you were not charged |
| Refunded | The customer: the money is coming back |
| The first of awaiting, paid or shipped | You: a new order came in |

Those are PrestaShop's own statuses, and the module reads their numbers from
your shop rather than assuming them, because they are different in every shop.
The settings page prints the ones it found, under **Messages to your
customers**, with the names your shop uses. If a line there says the status
does not exist in your shop, that message will never fire, and now you know.

Statuses your payment or shipping module added count too, if they are ticked
**Set the order as shipped** or **Consider the associated order as validated**
in **Statuses**. That is how a shop whose gateway installs its own "Payment
accepted" still gets the payment message.

The alert to you is sent **once per order**, on the first of those statuses it
reaches. Not when the order row is created, which happens the moment somebody
reaches the payment page and usually means nothing.

A **credit slip on its own sends nothing**. The refund message goes out when
you move the order to the Refunded status, which is the moment the customer's
money is actually on its way back.

## The wording

Plain text with placeholders in braces. The settings page lists them all; they
are `{first_name}`, `{last_name}`, `{customer_name}`, `{order_number}`,
`{order_total}`, `{order_date}`, `{order_status}`, `{payment_method}`,
`{items}`, `{item_count}`, `{shop_name}`, `{order_url}` and `{admin_url}`.

`{order_number}` is the order reference the customer sees, such as XKBKNABJK.

`{admin_url}` is a link to your back office, and it stops at the front door. It
does not open the order, because a link that opens one order carries the
sign-in token of whichever employee last opened the Njiwa settings page. That
token is not something to put in a WhatsApp message, and it would not work for
anybody else on your list anyway.

A placeholder that does not exist, `{order_no}` say, is removed before sending
rather than posted to a customer. You are told about it when you save, and
again in **Advanced Parameters → Logs**.

**Clearing the wording box turns that message off** without touching the switch
above it. That is deliberate: it is the quick way to stop one message.

## Things worth knowing

**The checkout never waits.** When an order changes status the module writes
one row and gets out of the way. The message is sent after the page has been
delivered to whoever was waiting for it. A slow network, or Njiwa being down,
cannot delay or break an order.

On most hosting that is the end of it. If your server cannot hand back a
finished page and carry on working, the shop knocks on its own front door
instead and rings off without waiting.

If **neither** works, and some hardened shared hosting allows neither, there is
a last resort: the page that changed the status sends the messages itself
before it finishes. It is held to three messages and about ten seconds, so that
one page is slow rather than stuck, and the order was saved before any of it
started. Anything past those three stays in the queue until the next order
changes status, or until a cron job comes along.

**On that hosting a cron job is not optional.** Point one every five minutes at
the address printed under **The message to you** and the queue empties on its
own. The settings page tells you how many messages are waiting, and a number
that never comes down is how you find out your hosting is in this position.
Keep that address private: it is the only thing that stops a stranger emptying
your queue.

**When a message cannot go out.** A network problem is not a delivery failure:
the message was never accepted, so trying again is safe. The module tries three
times, waiting five minutes and then twenty between attempts, which covers the
ordinary case of a connection that comes back. After the third attempt it stops
and writes the reason on the order, and the settings page says how many
messages it has given up on. Nothing tries those again by itself. **Send the
message(s) that failed again**, under **The message to you**, puts them all
back in the queue.

The one thing that button leaves alone is a send that was interrupted half way
and is now more than 24 hours old. Njiwa remembers a message for 24 hours and
replays it rather than sending it a second time; past that it can no longer
tell this module whether the first one arrived, and a duplicate to a customer
is worse than a gap. The settings page says how many of those there are, the
count is written to **Advanced Parameters → Logs**, and the Njiwa console shows
what was really sent.

**Every send is written on the order.** Open any order and the Messages block
says what went where, with Njiwa's message id, or why it did not. That is also
where "this order has no phone number" shows up. Those notes are private and
are never emailed to the customer.

**Nothing is sent twice.** Every message is claimed in the module's own table
before it is attempted, keyed on the order, the moment and the number, so an
order that reaches the same status twice, or a bulk status update run again,
sends one message. Each message also carries an idempotency key, so if a worker
dies half way and tries again within 24 hours, Njiwa replays the first answer
instead of messaging the customer a second time.

**A phone number is read against the country on the order.** `0712345678` on an
order with a Kenyan address becomes `254712345678`, because PrestaShop knows
each country's dialling code. A number already written in full is left alone.
The mobile number on the address is preferred over the other one, since a
landline is a message nobody will ever see. A customer with no phone number is
normal: nothing is sent, and it is not an error.

**A WhatsApp group is never messaged.** Anything containing an `@` is refused
outright, wherever it was typed. A group address in the wrong box would
otherwise message every person in that group from your own number.

**Your API key is encrypted** with the shop's own encryption where PrestaShop
provides it, and it is never written back into the settings page: the box stays
empty and the page tells you the last four characters of the key that is saved.
Leave the box empty to keep that key. If you move your shop and its cookie key
is regenerated, the saved key can no longer be read, and the settings page says
so and asks you to paste it in again.

**Removing the module removes the key**, the settings and the record of what
was sent. Notes already written on orders stay, because they belong to the
order.

## What it does not do

**It does not receive replies.** Inbound WhatsApp and delivery receipts arrive
as webhooks, and verifying one needs that number's signing secret, which the
console does not yet show. Until it does, a receiving feature could not check
that a request really came from Njiwa, so there is not one.

**It does not run campaigns.** Bulk sending to past customers is what the Njiwa
console is for, on Business plans and above.

**It does not keep its own copy of your messages.** Njiwa already stores every
message, its status and its failure reason. What this module keeps is a list of
what it has been asked to send, so that it never asks twice.

---

Docs: https://docs.njiwa.upeo.ai · Console: https://console.upeo.ai
UPEO.AI · hello@upeo.ai · 0116888777 on WhatsApp
