<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Exceptions;

/** Only controlled, credential-free messages may be exposed in the admin status. */
final class RouterException extends \RuntimeException {}
