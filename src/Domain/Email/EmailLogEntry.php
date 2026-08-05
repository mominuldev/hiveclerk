<?php
/**
 * Email log entry.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Email;

use DateTimeImmutable;

/**
 * One email, whether it went out or not.
 *
 * Suppressed sends get a row too. "We did not email this person because
 * they unsubscribed in March" is the answer to a complaint, and it only
 * exists if the decision was written down at the time.
 *
 * `openedAt` and `clickedAt` are columns the schema has had since M0005
 * and this sprint does not fill them — open tracking needs a pixel and
 * click tracking needs rewritten links, both of which are decisions about
 * a customer's relationship with their own recipients rather than
 * plumbing. They stay null and the UI shows no metric rather than a zero
 * that looks like nobody read anything.
 */
final class EmailLogEntry {

	/**
	 * Construct.
	 *
	 * @param int|null               $id           Storage id.
	 * @param int                    $leadId       Recipient lead.
	 * @param string                 $toEmail      Address it went to.
	 * @param string                 $subject      Subject as sent, merge tags resolved.
	 * @param SendStatus             $status       What happened.
	 * @param int|null               $enrollmentId Enrolment, for sequence mail.
	 * @param int|null               $stepId       Step, for sequence mail.
	 * @param string|null            $messageId    Message-ID header, where one was set.
	 * @param string|null            $error        Failure reason.
	 * @param DateTimeImmutable|null $sentAt       When the mailer took it, UTC.
	 * @param DateTimeImmutable|null $openedAt     Reserved; never written this sprint.
	 * @param DateTimeImmutable|null $clickedAt    Reserved; never written this sprint.
	 * @param DateTimeImmutable|null $createdAt    Row creation, UTC.
	 */
	public function __construct(
		public ?int $id,
		public int $leadId,
		public string $toEmail,
		public string $subject,
		public SendStatus $status = SendStatus::Queued,
		public ?int $enrollmentId = null,
		public ?int $stepId = null,
		public ?string $messageId = null,
		public ?string $error = null,
		public ?DateTimeImmutable $sentAt = null,
		public ?DateTimeImmutable $openedAt = null,
		public ?DateTimeImmutable $clickedAt = null,
		public ?DateTimeImmutable $createdAt = null,
	) {
	}
}
