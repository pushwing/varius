<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Traits\ApiResponse;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

abstract class BaseApiController extends Controller
{
    use ApiResponse;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
    }

    /**
     * @return array<string, mixed>
     */
    protected function jsonBody(): array
    {
        /** @var IncomingRequest|CLIRequest $request */
        $request = $this->request;
        if (!$request instanceof IncomingRequest) {
            return [];
        }

        return (array) ($request->getJSON(true) ?? []);
    }
}
