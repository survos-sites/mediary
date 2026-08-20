<?php

declare(strict_types=1);

namespace App\Menu;

use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Traits\KnpMenuHelperInterface;
use Survos\TablerBundle\Traits\KnpMenuHelperTrait;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class AppMenu implements KnpMenuHelperInterface
{
    use KnpMenuHelperTrait;

    public function __construct(
        #[Autowire('%kernel.environment%')] protected string $env,
        private Security $security,
        private ?AuthorizationCheckerInterface $authorizationChecker = null
    ) {
    }

    public function appAuthMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();

        if ($this->security->getUser()) {
            $this->add($menu, 'app_logout', label: 'Logout', icon: 'logout');

            return;
        }

        $this->add($menu, 'app_login', label: 'Login', icon: 'login');
        $this->add($menu, 'app_register', label: 'Register', icon: 'user-plus');
    }

    #[AsEventListener(event: MenuEvent::NAVBAR_MENU)]
    public function navbarMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();

        $this->add($menu, 'app_homepage', label: 'Home', icon: 'home');

        $assetsMenu = $this->addSubmenu($menu, 'Assets', icon: 'assets');
        $this->add($assetsMenu, 'app_browse_assets', label: 'Browse Assets');
        $this->add($assetsMenu, 'asset_search', label: 'Search Assets');
        // api-grid over /api/assets.json — the listing that renders rows client-side from
        // the API payload, so it's where the inline ThumbHash blur shows up.
        $this->add($assetsMenu, 'asset_browse_grid', label: 'Assets Grid');

        $recordsMenu = $this->addSubmenu($menu, 'Records', icon: 'records');
        $this->add($recordsMenu, 'media_record_browse', label: 'Browse Records');
        $this->add($recordsMenu, 'meili_insta', ['indexName' => 'mediarecord'], label: 'Search Records');

        $this->add($menu, 'iiif_browse', label: 'IIIF', icon: 'iiif');
        $this->add($menu, 'survos_state_workflow_dashboard', label: 'Summary', icon: 'summary');
    }
}
