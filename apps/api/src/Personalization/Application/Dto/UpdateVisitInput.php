<?php

declare(strict_types=1);

namespace App\Personalization\Application\Dto;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final readonly class UpdateVisitInput
{
    public ?\DateTimeImmutable $visitedOn;
    public bool $hasVisitedOn;
    public ?string $note;
    public bool $hasNote;

    public function __construct(?\DateTimeImmutable $visitedOn, bool $hasVisitedOn, ?string $note, bool $hasNote)
    {
        $this->visitedOn = $visitedOn;
        $this->hasVisitedOn = $hasVisitedOn;
        $this->note = $note;
        $this->hasNote = $hasNote;
    }

    public static function fromRequest(Request $request): self
    {
        $contentType = $request->headers->get('Content-Type') ?? '';
        if (!str_contains($contentType, 'application/json') && 'json' !== $request->getContentTypeFormat()) {
            throw new BadRequestHttpException('Content-Type must be application/json.');
        }

        $content = $request->getContent();
        $data = json_decode($content, true);
        if (\JSON_ERROR_NONE !== json_last_error() || !\is_array($data)) {
            throw new BadRequestHttpException('Invalid JSON payload.');
        }

        // Reject extra fields
        $allowedFields = ['visitedOn', 'note'];
        foreach (array_keys($data) as $key) {
            if (!\in_array($key, $allowedFields, true)) {
                throw new BadRequestHttpException(\sprintf('Extra field "%s" is not allowed.', $key));
            }
        }

        $hasVisitedOn = \array_key_exists('visitedOn', $data);
        $hasNote = \array_key_exists('note', $data);

        $visitedOn = null;
        if ($hasVisitedOn) {
            $visitedOnStr = $data['visitedOn'];
            if (null === $visitedOnStr || !\is_string($visitedOnStr)) {
                throw new BadRequestHttpException('Invalid visitedOn parameter.');
            }

            // Exact Y-m-d check
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitedOnStr)) {
                throw new BadRequestHttpException('Date must be in exact Y-m-d format.');
            }

            $visitedOn = \DateTimeImmutable::createFromFormat('Y-m-d', $visitedOnStr);
            if (!$visitedOn || $visitedOn->format('Y-m-d') !== $visitedOnStr) {
                throw new BadRequestHttpException('Date must be a valid calendar date.');
            }
            $visitedOn = $visitedOn->setTime(0, 0, 0);

            // No future dates
            $today = (new \DateTimeImmutable('now'))->setTime(0, 0, 0);
            if ($visitedOn > $today) {
                throw new UnprocessableEntityHttpException('Visited date cannot be in the future.');
            }
        }

        $note = null;
        if ($hasNote) {
            $note = $data['note'];
            if (null !== $note) {
                if (!\is_string($note)) {
                    throw new BadRequestHttpException('Note must be a string or null.');
                }
                if (mb_strlen($note) > 1000) {
                    throw new UnprocessableEntityHttpException('Visit note cannot exceed 1000 characters.');
                }
            }
        }

        return new self($visitedOn, $hasVisitedOn, $note, $hasNote);
    }
}
