<?php

namespace App\Mcp\Tools;

use RuntimeException;

/** A request the tool understood but cannot carry out. The message goes back to the AI as is. */
class ToolFailure extends RuntimeException {}
