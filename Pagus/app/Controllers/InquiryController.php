<?php

namespace App\Controllers;

use App\Services\InquiryService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

final class InquiryController extends Controller
{
    private InquiryService $inquiries;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->inquiries = new InquiryService();
    }

    public function create(): RedirectResponse
    {
        $rules = ['name' => 'required|max_length[100]', 'contact' => 'permit_empty|max_length[255]', 'message' => 'required|max_length[2000]'];
        if (! $this->validate($rules)) {
            return redirect()->to('/#contact')->withInput()->with('error', '이름과 문의 내용을 확인하세요.');
        }
        try {
            $this->inquiries->submit($this->request->getPost());
        } catch (InvalidArgumentException $exception) {
            return redirect()->to('/#contact')->withInput()->with('error', $exception->getMessage());
        }
        return redirect()->to('/#contact')->with('message', '문의가 접수되었습니다.');
    }
}
