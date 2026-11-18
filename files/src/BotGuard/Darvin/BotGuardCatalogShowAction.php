<?php

declare(strict_types=1);

namespace App\BotGuard\Darvin;

use App\BotGuard\BotGuardCatalogAdmission;
use Darvin\ECommerceBundle\Controller\Catalog\Action\ShowAction;
use Darvin\ECommerceBundle\Entity\Catalog;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BotGuardCatalogShowAction extends ShowAction
{
    private $inner;
    private $admission;

    public function __construct(ShowAction $inner, BotGuardCatalogAdmission $admission)
    {
        $this->inner = $inner;
        $this->admission = $admission;
    }

    public function __invoke(Request $request, Catalog $catalog, bool $checkAccessibility = true, ?string $filterExpression = null): Response
    {
        return $this->admission->run($request, $filterExpression, function () use ($request, $catalog, $checkAccessibility, $filterExpression): Response {
            return $this->inner->__invoke($request, $catalog, $checkAccessibility, $filterExpression);
        });
    }
}
