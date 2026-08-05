<?php
/**
 * Seeds a realistic business corpus for the retrieval evaluation.
 *
 * Run with:  wp eval-file tools/eval/seed-corpus.php
 *
 * ## Why this exists and what it is not
 *
 * `tools/retrieval-eval.php` has never produced a real number, because the
 * development site had two chunks and no embedding-capable key. The M1
 * recall criterion has therefore been open since Sprint 4. Measuring it
 * needs two things that did not exist: content worth retrieving from, and a
 * question set written in a visitor's words rather than the page's.
 *
 * This is the first half. The pages are the kind a real customer site has —
 * delivery, returns, warranty, sizing, payment, accounts — written as prose
 * rather than as keyword bait, because retrieval that only works on tidy
 * content is retrieval that does not work.
 *
 * **It is authored, not harvested, and that is a real limitation.** A corpus
 * and a question set written by the same hand share assumptions a customer's
 * site and a stranger's question do not. The questions in `questions.json`
 * are deliberately phrased away from the page text to blunt that, and the
 * resulting figure should still be read as better than a probe run and
 * weaker than a measurement against a real shop.
 *
 * @package Hiveclerk
 */

$hvc_pages = array(

	'Delivery and shipping' => <<<'TXT'
We send every order from our warehouse in Leeds. Standard delivery within the United Kingdom costs £3.95 and usually arrives in three to five working days. Orders placed before 2pm on a working day leave the same afternoon; anything after that goes out the next morning.

Express delivery costs £8.50 and arrives the next working day, provided the order was placed before 2pm. We do not despatch on Saturdays, Sundays or bank holidays, so an express order placed on Friday afternoon arrives on Monday.

Delivery is free on any order over £60 before discounts are applied. The free option is always the standard service; upgrading to express still costs £8.50 on top.

We ship to the Republic of Ireland for £9.95, and to most of western Europe for £14.95. Parcels to Europe usually take seven to ten working days. We do not currently ship to North America, Australia or New Zealand.

Every parcel is sent with a tracking number, which we email as soon as the label is printed. If nobody is in, the courier leaves a card and attempts delivery twice more on the following working days.
TXT,

	'Returns and refunds' => <<<'TXT'
If something is not right, you have 30 days from the day your parcel arrives to send it back. The item needs to be unworn, unwashed and in the packaging it came in, with the tags still attached.

To start a return, sign in to your account, open the order and choose Return items. We will email you a prepaid label. Print it, attach it to the parcel, and drop it at any post office or parcel shop.

Returns are free from mainland Britain. From Ireland and Europe the return postage is £6.95, deducted from the refund.

Refunds go back to the card or account you paid with, and take three to five working days to appear once the parcel reaches our warehouse. We refund the item and, if you are sending the whole order back, the original standard delivery charge as well. Express delivery charges are not refunded.

Sale items follow the same 30 days. Pierced jewellery and underwear cannot be returned once the hygiene seal is broken, unless they are faulty.

If you would rather have a different size or colour, send the original back and place a new order. We do not process direct exchanges, because holding stock while a parcel is in transit means somebody else cannot buy it.
TXT,

	'Warranty and repairs' => <<<'TXT'
Everything we sell carries a two-year guarantee against faults in the materials or the making of it. That runs from the date on your receipt, not the date you first wore the item.

The guarantee covers seams that come apart, zips that fail, hardware that breaks and coatings that peel. It does not cover ordinary wear — thinning fabric, fading, scuffed leather — or damage from an accident, a hot wash, or a repair somebody else attempted.

To make a claim, email photographs of the fault and your order number to our support team. We will normally answer within two working days. If the fault is covered we repair the item where we can and replace it where we cannot; if the same product is no longer made, we offer the nearest equivalent or a full refund.

Outside the guarantee we still repair things. A replacement zip is £18, a re-stitched seam is £12, and a new set of hardware is £15 including fitting. Send the item in with a note and we will invoice you before starting any work, so nothing is done that you have not agreed to.

Repairs take about three weeks in summer and up to five in the run-up to Christmas.
TXT,

	'Sizing and fit' => <<<'TXT'
Our sizes follow standard British measurements. A size 12 in our womenswear corresponds to a 36 inch bust and a 30 inch waist; a men's medium fits a 40 inch chest.

Jackets and coats are cut with room for a jumper underneath, so if you are between sizes we suggest taking the smaller one. Trousers and jeans are cut close and do not stretch much after the first few wears, so between sizes take the larger.

Every product page has a measurement table showing the garment laid flat, which is usually more useful than a size label. Measure something you already own and compare.

Our shoes run about half a size small. If you are normally a 9, order a 9 and a half. Wide fittings are available on the walking boots and the leather brogues, marked with a W after the size.

If a garment does not fit, the 30 day return window applies as normal, and returns from mainland Britain are free.
TXT,

	'Payment and pricing' => <<<'TXT'
We take Visa, Mastercard, American Express, Apple Pay, Google Pay and PayPal. We also accept Klarna, which lets you pay in three instalments with no interest.

Prices on the site include VAT at the current rate. Orders going outside the United Kingdom have VAT removed at checkout, but the destination country may charge its own duty and tax on arrival, which is the recipient's responsibility and not something we can pay on your behalf.

Your card is authorised when you place the order and charged when the parcel leaves the warehouse. If an item turns out to be out of stock, we charge only for what we send.

Gift cards come as a code by email and can be spent in one go or over several orders. They last two years from the day they are issued and cannot be exchanged for cash.

Discount codes apply to full-price items only unless the code says otherwise, and only one can be used per order. The free delivery threshold is worked out on the total before any discount is applied.
TXT,

	'Accounts and orders' => <<<'TXT'
You do not need an account to buy something — there is a guest checkout — but an account makes returns quicker and keeps your order history in one place.

To change or cancel an order, contact us as fast as you can. Once a parcel has a tracking number it has already been picked and packed and we cannot stop it, though you can of course return it when it arrives.

If you have forgotten your password, use the Forgotten password link on the sign-in page. The reset email arrives within a few minutes; check your spam folder before contacting us, because filters catch it more often than we would like.

We keep your order history for six years, which is what our accountants require. You can ask us to delete your account and personal details at any time, and we will do so within a month, keeping only what the law requires us to hold.

We never store your full card number. Payments are handled by our payment provider and we only ever see the last four digits.
TXT,

	'Opening hours and contact' => <<<'TXT'
Our shop on Briggate is open Monday to Saturday from 9am until 6pm, and on Sunday from 11am until 5pm. We close on Christmas Day, Boxing Day and New Year's Day, and open late until 8pm on Thursdays in December.

The support team answers email between 9am and 5pm on working days. We aim to reply within one working day and almost always manage it, though the week after Christmas is slower.

The phone line is open from 9am to 5pm Monday to Friday on 0113 496 0000. There is no phone line at weekends.

Our warehouse is not open to the public and cannot accept collections or returns in person. Returns must go through the post, even if you live nearby.

If you want to try something on before buying, the Briggate shop carries most of the range, though not every colour of every item.
TXT,

	'Product care' => <<<'TXT'
Most of our cotton can be washed at 30 degrees on a normal cycle. Turn printed items inside out first, which keeps the print from rubbing against the drum.

Wool needs more care. Use a wool cycle or wash by hand in cool water with a wool detergent, never biological powder, which digests the fibres. Do not wring it. Press the water out flat between two towels and dry it lying down rather than on a hanger, or the shoulders stretch.

Waxed cotton should never go in a washing machine. Sponge it with cold water and re-wax it once a year with the tin we sell; heat the jacket gently with a hairdryer first so the wax soaks in.

Leather wants a wipe with a damp cloth and a conditioner twice a year. If it gets soaked, let it dry away from a radiator — direct heat makes leather brittle and it will crack along the creases.

Nothing we sell should go in a tumble dryer. It shortens the life of every fibre we use and will shrink the wool.
TXT,

	'Stock and availability' => <<<'TXT'
If a size is greyed out on a product page, it has sold out. Use the Notify me link and we will email you once it is back, usually within three to four weeks for a repeat line.

Some products are made in a single run and are not repeated. Those are marked Limited edition, and once they are gone they are gone for good.

We hold most stock in the Leeds warehouse, so what the website says is what is on the shelf. The count updates every few minutes, which means very occasionally two people buy the last one at the same time. If that happens we email you the same day and refund immediately.

Pre-orders take payment at the time you order and ship on the date shown on the product page. If that date moves by more than two weeks we will email you and you can cancel for a full refund.

The Briggate shop and the website hold separate stock, so something sold out online may still be on the rail in town.
TXT,

	'Wholesale and trade' => <<<'TXT'
We supply about ninety independent shops across Britain and Ireland. The minimum opening order is £1,500 and repeat orders start at £500.

Trade prices are half the recommended retail price. Payment is 30 days from invoice once we have agreed an account; the first two orders are paid up front while references are checked.

We work two seasons ahead. Autumn and winter is shown in January, spring and summer in July. Order deadlines are six weeks after each showing, and delivery is the following August and February respectively.

We do not sell to online-only resellers or marketplace sellers, because we cannot control how the brand is presented there. This is not negotiable and applies regardless of order size.

To open an account, email the trade team with your shop's name, address, company number and a note about what else you stock. We answer every enquiry, though not always quickly in the weeks around a trade show.
TXT,

	'Sustainability and materials' => <<<'TXT'
About seventy per cent of the cotton we buy is organic and certified to the Global Organic Textile Standard. The rest is conventional cotton bought through the Better Cotton Initiative, and we are moving lines across as the contracts come up.

Our wool comes from two mills, one in Yorkshire and one in Donegal. Neither uses mulesing and both are audited annually. We publish the audit summaries on the site each spring.

We do not use virgin polyester in any garment. Where a synthetic is needed for weatherproofing we use recycled polyester made from bottle stock, which is why some waterproof shells have a slight variation in colour between batches.

Packaging is paper and cardboard, with no plastic film. The tape is paper and the whole parcel can go in a household recycling bin.

We are not carbon neutral and we do not buy offsets, because we would rather spend the money reducing the emissions than accounting for them. Our emissions report is published each year and shows the direction honestly, including the years it went the wrong way.
TXT,

	'Gift wrapping and gift cards' => <<<'TXT'
Gift wrapping costs £3.50 per item and comes as recycled kraft paper with a cloth ribbon and a handwritten card. Add the message at checkout; there is room for about forty words.

Wrapped items are packed so the price does not appear anywhere in the parcel, and the delivery note is sent to the buyer by email rather than included in the box.

Gift cards are delivered by email as a code, usually within a few minutes but occasionally up to an hour at busy times. They can be sent straight to the recipient on a date you choose.

A gift card can be spent online or in the Briggate shop. It can be used across several orders until the balance runs out, and the remaining balance is shown at checkout.

If somebody was given something that is not right, they can return it with the order number on the gift card message, and we will refund them to a gift card rather than to the buyer's card, so the present stays private.
TXT,
);

// ---------------------------------------------------------------------------

$hvc_created = 0;
$hvc_map     = array();

foreach ( $hvc_pages as $hvc_title => $hvc_body ) {
	$hvc_existing = get_page_by_path( sanitize_title( $hvc_title ), OBJECT, 'page' );

	if ( $hvc_existing ) {
		wp_delete_post( (int) $hvc_existing->ID, true );
	}

	$hvc_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $hvc_title,
			'post_name'    => sanitize_title( $hvc_title ),
			'post_content' => wpautop( $hvc_body ),
		),
		true
	);

	if ( is_wp_error( $hvc_id ) ) {
		echo "FAILED {$hvc_title}: " . $hvc_id->get_error_message() . "\n";

		continue;
	}

	++$hvc_created;
	$hvc_map[ $hvc_title ] = (int) $hvc_id;

	printf( "  %-32s post_id=%d  words=%d\n", $hvc_title, $hvc_id, str_word_count( $hvc_body ) );
}

echo "\n{$hvc_created} pages seeded.\n";
echo "Now add a 'Site content' knowledge source and index it, then run:\n";
echo "  wp eval-file tools/retrieval-eval.php tools/eval/questions.json 5\n";
