<?php

namespace Digit7s\AuditToolkit\Contracts;

use Digit7s\AuditToolkit\Data\AuditEventData;
use Digit7s\AuditToolkit\Models\AuditEvent;

interface AuditRecorder
{
    public function record(AuditEventData $event): ?AuditEvent;
}
