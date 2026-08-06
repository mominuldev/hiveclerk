=== Hiveclerk ===
Contributors: decenthemes
Tags: ai, chatbot, live chat, lead generation, customer support
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: trunk
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A hive of AI clerks for your website. Deploy AI employees that talk to visitors, qualify leads and answer support questions.

== Description ==

Hiveclerk staffs your WordPress site with AI clerks — named, configurable workers with a job description, a knowledge base and measurable results.

Unlike hosted chatbot services, Hiveclerk runs on your own hosting and uses your own model API key. Your conversations, leads and knowledge stay in your database. You pay for the software once a year, not per conversation.

= Give a clerk a job =

Pick a role — support, sales, lead qualifier, FAQ or concierge — then write what the clerk should do in plain language. Set its tone, its limits, and what it must never claim.

= Answers grounded in your own content =

Point a clerk at your pages, products, PDFs or FAQs. It answers from that content and shows which source it used, so you can check any reply. Turn on "never invent facts" and it will say it does not know rather than guess at a price.

= Qualify leads while you sleep =

Clerks capture contact details conversationally, ask your qualification questions, and score the lead against rules you set. Every point is attributed, so your sales team can see exactly why a lead scored what it did.

= Know what it costs =

Every conversation records its token use and cost. Set a monthly budget per clerk and it stops rather than overspending.

= Your data stays yours =

Conversations, leads and knowledge live in your site's database. Only the message text is sent to the model provider you chose. Export or erase any visitor's data with WordPress's built-in privacy tools.

== External services ==

Hiveclerk is self-hosted and stores everything in your own database. It does,
however, talk to services you choose to connect. Each one is listed here with
what is sent and when.

**Model providers.** A clerk cannot answer without one, and you supply the key.
When a visitor sends a message, the message text, the relevant extracts from
your own indexed content, and the clerk's instructions are sent to the provider
you configured so it can generate a reply. Indexing your content also sends
that content to the provider to be embedded. No provider is contacted until you
add a key for it.

* Anthropic — https://www.anthropic.com/legal/consumer-terms · https://www.anthropic.com/legal/privacy
* OpenAI — https://openai.com/policies/terms-of-use · https://openai.com/policies/privacy-policy
* Google (Gemini) — https://ai.google.dev/gemini-api/terms · https://policies.google.com/privacy
* Azure OpenAI — https://azure.microsoft.com/support/legal/ · https://privacy.microsoft.com/privacystatement
* OpenRouter — https://openrouter.ai/terms · https://openrouter.ai/privacy

**CRM and notification connectors.** Optional, and inactive until you connect
one. When a lead qualifies, that lead's contact details and the answers they
gave are sent to the destination you connected.

* HubSpot — https://legal.hubspot.com/terms-of-service · https://legal.hubspot.com/privacy-policy
* Slack — https://slack.com/terms-of-service · https://slack.com/trust/privacy/privacy-policy
* Custom webhook — sends to whatever URL you enter, and nowhere else

FluentCRM and Groundhogg are WordPress plugins running on your own site. Syncing
to either sends nothing off your server.

**Licence server (licence.hiveclerk.com).** Contacted when you activate or
deactivate a licence key, and every twelve hours afterwards to confirm the key
is still valid. It sends the key, your site URL and the plugin version. It is
not contacted at all until a key is entered. Terms and privacy policy:
https://hiveclerk.com/terms · https://hiveclerk.com/privacy

Nothing else leaves your server. There is no analytics, no telemetry and no
phone-home on install.

== Frequently Asked Questions ==

= Do I need an API key? =

Yes. Hiveclerk works with Anthropic, OpenAI, Google, Azure OpenAI and OpenRouter. You add your own key and pay the provider directly, which is why there is no per-conversation charge from us.

= Does it work with WooCommerce? =

Yes. Clerks can index your products and answer questions about them. Cart recovery, recommendations and checkout assistance arrive in version 2.

= Will it slow my site down? =

The chat widget is 17 KB gzipped, loads asynchronously, and only loads on pages where a clerk is set to appear. Pages with no clerk assigned load nothing at all.

= Can I use it on client sites? =

Yes. The Agency licence covers 25 sites and includes white-label mode.

== Screenshots ==

1. Dashboard with conversation volume, qualified leads and spend
2. The clerk editor with a live test console
3. Knowledge gaps — questions your clerks could not answer
4. Lead pipeline with attributed score breakdowns

== Changelog ==

The full engineering changelog, including what was not delivered in each
sprint and what remains untested, is in CHANGELOG.md in the plugin folder.

= 0.1.0 =
* Not yet released. Development builds only; see CHANGELOG.md.

== Upgrade Notice ==

= 0.1.0 =
First development release. Not yet ready for production sites.
