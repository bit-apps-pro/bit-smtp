<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\SparkPost;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Support\AddressFormatter;
use BitApps\SMTP\Mail\Support\AttachmentBuilder;
use BitApps\SMTP\Mail\Support\SenderResolver;

/**
 * Builds SparkPost's transmissions body (spec §5/§11 payloadBuilder escape hatch): SparkPost has
 * no native cc/bcc, so every recipient (to+cc+bcc) is flattened into `recipients[]` and the visible
 * To/Cc headers are rendered separately in `content.headers` — bcc addresses are deliberately never
 * written to any header, which is what keeps a blind-carbon-copy blind.
 */
final class SparkPostPayloadBuilder
{
    private AddressFormatter $addressFormatter;

    private AttachmentBuilder $attachmentBuilder;

    private SenderResolver $senderResolver;

    public function __construct()
    {
        $this->addressFormatter  = new AddressFormatter();
        $this->attachmentBuilder = new AttachmentBuilder();
        $this->senderResolver    = new SenderResolver();
    }

    public function build(MailMessage $message, Connection $connection): array
    {
        $to  = $message->getTo();
        $cc  = $message->getCc();
        $bcc = $message->getBcc();

        return [
            'recipients' => $this->buildRecipients($to, $cc, $bcc),
            'content'    => $this->buildContent($message, $connection, $cc),
        ];
    }

    /**
     * @param string[] $to
     * @param string[] $cc
     * @param string[] $bcc
     */
    private function buildRecipients(array $to, array $cc, array $bcc): array
    {
        $headerTo = $this->joinRfc822($to);

        return array_map(function (string $address) use ($headerTo): array {
            return [
                'address' => [
                    'email'     => $this->bareEmail($address),
                    'header_to' => $headerTo,
                ],
            ];
        }, array_merge($to, $cc, $bcc));
    }

    /**
     * @param string[] $cc
     */
    private function buildContent(MailMessage $message, Connection $connection, array $cc): array
    {
        $from    = $this->senderResolver->from($message, $connection);
        $replyTo = $this->senderResolver->replyTo($message, $connection);

        $content = [
            'from'    => $from[0] ?? '',
            'subject' => $message->getSubject(),
        ];

        $bodyKey           = stripos($message->getContentType(), 'text/html') === 0 ? 'html' : 'text';
        $content[$bodyKey] = $message->getBody();

        if (!empty($replyTo)) {
            $content['reply_to'] = $replyTo[0];
        }

        // Cc is a visible header; bcc must never appear in any content.headers value.
        if (!empty($cc)) {
            $content['headers'] = ['CC' => $this->joinRfc822($cc)];
        }

        $attachments = $message->getAttachments();
        if (!empty($attachments)) {
            $content['attachments'] = $this->attachmentBuilder->build($attachments, AttachmentBuilder::SHAPE_SPARKPOST);
        }

        return $content;
    }

    /**
     * @param string[] $addresses
     */
    private function joinRfc822(array $addresses): string
    {
        if (empty($addresses)) {
            return '';
        }

        $formatted = $this->addressFormatter->format($addresses, AddressFormatter::SHAPE_RFC822);

        return \is_array($formatted) ? implode(', ', $formatted) : $formatted;
    }

    private function bareEmail(string $address): string
    {
        return $this->addressFormatter->format([$address], AddressFormatter::SHAPE_CSV);
    }
}
