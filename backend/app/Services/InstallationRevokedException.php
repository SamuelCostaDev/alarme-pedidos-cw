<?php

namespace App\Services;

use RuntimeException;

/** A CW respondeu 401: a loja desinstalou o app ou os tokens foram revogados. */
class InstallationRevokedException extends RuntimeException {}
