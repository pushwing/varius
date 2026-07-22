<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;

/**
 * @internal
 */
final class HomeControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    /**
     * FeatureTestTrait 로 시뮬레이션한 요청은 (CI4 testing 환경의 debug 뷰 래핑 경로 특성상)
     * 응답 본문의 비ASCII 문자가 HTML 숫자 엔티티로 나올 수 있다 — 실제 브라우저·spark serve
     * 응답에는 나타나지 않는 테스트 하네스 한정 현상(실사용자 영향 없음, 이 프로젝트의 기존
     * map.php 렌더링에서도 동일하게 재현됨). 엔티티 디코딩 후 비교해 두 표현 모두 견고하게 통과시킨다.
     */
    private function decodedBody(TestResponse $result): string
    {
        return html_entity_decode((string) $result->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function testShowsLoginButtonWhenNotLoggedIn(): void
    {
        $result = $this->get('/');

        $result->assertStatus(200);
        $body = $this->decodedBody($result);
        $this->assertStringContainsString('Google로 로그인', $body);
        $this->assertStringContainsString('/auth/google', $body);
        $this->assertStringNotContainsString('class="brand"', $body);
        $this->assertStringNotContainsString('id="start-picker"', $body);
    }

    public function testShowsMenuAndHidesLoginCtaWhenLoggedIn(): void
    {
        // '/' 는 로그인 여부와 무관하게 항상 렌더되지만, 로그인 사용자에게는 로그인 CTA 대신
        // 상단 메뉴와 "사진 가져오기" 버튼을 보여준다 — 업로드 폼 자체는 GET /upload 에서 제공된다.
        $userId = (new UserModel())->upsertByGoogleSub('sub-home', 'home@example.com', 'Home');

        $result = $this->withSession(['user_id' => $userId])->get('/');

        $result->assertStatus(200);
        $body = $this->decodedBody($result);
        $this->assertStringContainsString('class="brand"', $body);
        $this->assertStringContainsString('/upload', $body);
        $this->assertStringContainsString('지도 보기', $body);
        $this->assertStringContainsString('내 여행', $body);
        $this->assertStringContainsString('로그아웃', $body);
        $this->assertStringContainsString('/auth/logout', $body);
        $this->assertStringNotContainsString('Google로 로그인', $body);
        $this->assertStringNotContainsString('id="takeout-form"', $body);
    }
}
