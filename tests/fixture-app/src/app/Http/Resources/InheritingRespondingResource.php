<?php

declare(strict_types=1);

namespace App\Http\Resources;

/**
 * Mentions `toResponse()` nowhere: {@see SelfRespondingResource} wrote the response this sends, and a
 * `toResponse()` added HERE would replace it.
 */
class InheritingRespondingResource extends SelfRespondingResource {}
