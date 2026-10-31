<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Probe;

use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

use function max;
use function sprintf;

/**
 * Reports the number of messages waiting in one Messenger transport and compares it to thresholds.
 *
 * The count comes from the transport itself ({@see MessageCountAwareInterface}: Doctrine, Redis, AMQP, Beanstalkd…),
 * one call per transport. Reaching `fail_at` fails the probe, reaching `warn_at` degrades it.
 */
final readonly class MessengerTransportProbe implements HealthProbeInterface
{
    /**
     * @param object $transport transport service (`messenger.transport.<name>`)
     * @param int|null $warnAt message count from which the probe is degraded (null: never)
     * @param int|null $failAt message count from which the probe fails (null: never)
     */
    public function __construct(
        private object $transport,
        private ?int $warnAt = null,
        private ?int $failAt = null,
    ) {
    }

    /**
     * Counts the queued messages.
     *
     * @param float $timeout seconds allowed (enforced by the checker; the transport uses its own client timeouts)
     *
     * @return ProbeResult outcome with `messages` (and the thresholds) in data
     */
    public function check(float $timeout): ProbeResult
    {
        if (!$this->transport instanceof MessageCountAwareInterface) {
            return ProbeResult::degraded('The transport cannot report its message count.');
        }

        $count = max(0, $this->transport->getMessageCount());
        $data  = ['messages' => $count, 'warn_at' => $this->warnAt, 'fail_at' => $this->failAt];

        if ($this->failAt !== null && $count >= $this->failAt) {
            return ProbeResult::error(sprintf('%d messages waiting (fail threshold %d).', $count, $this->failAt), $data);
        }
        if ($this->warnAt !== null && $count >= $this->warnAt) {
            return ProbeResult::degraded(sprintf('%d messages waiting (warning threshold %d).', $count, $this->warnAt), $data);
        }

        return ProbeResult::ok($data);
    }
}
