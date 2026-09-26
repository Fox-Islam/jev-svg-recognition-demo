<?php

declare(strict_types=1);

namespace Phox\JevSvgDemo;

use RuntimeException;

/** A request the server refuses with a 400, its message shown to the page. */
final class BadRequest extends RuntimeException {}
