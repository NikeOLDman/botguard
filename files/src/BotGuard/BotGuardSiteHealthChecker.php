<?php

declare(strict_types=1);

namespace App\BotGuard;

use Darvin\ECommerceBundle\Entity\Product;
use Darvin\PageBundle\Entity\Homepage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

/** Readiness of the application's database, homepage data, routes and templates. */
class BotGuardSiteHealthChecker
{
    private $em;
    private $router;
    private $twig;
    private $locale;
    private $templates;

    public function __construct(EntityManagerInterface $em, RouterInterface $router, Environment $twig, string $locale, array $templates)
    {
        $this->em = $em;
        $this->router = $router;
        $this->twig = $twig;
        $this->locale = $locale;
        $this->templates = $templates;
    }

    public function check(): void
    {
        // Do not use HomepageProvider: its result cache can hide a database outage.
        $homepage = $this->em->createQuery(sprintf(
            'SELECT h.id, t.heading FROM %s h JOIN h.translations t WHERE t.locale = :locale',
            Homepage::class
        ))->setParameter('locale', $this->locale)->setMaxResults(1)->disableResultCache()->getOneOrNullResult();

        if (!is_array($homepage) || '' === trim((string) ($homepage['heading'] ?? ''))) {
            throw new \RuntimeException('Homepage data is unavailable.');
        }

        // Exercise the real catalogue schema as well; an empty catalogue is valid.
        $this->em->createQuery(sprintf('SELECT p.id FROM %s p', Product::class))
            ->setMaxResults(1)->disableResultCache()->getScalarResult();
        $this->router->generate('darvin_page_homepage');
        foreach ($this->templates as $template) {
            $this->twig->load($template);
        }
    }
}
