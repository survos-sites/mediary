<?php

declare(strict_types=1);

namespace App\Menu;

use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\MenuBuilderTrait;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class AppMenu
{
    use MenuBuilderTrait;

    public function __construct(
        #[Autowire('%kernel.environment%')] protected string $env,
        private Security $security,
    ) {
    }

    public function appAuthMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();

        if ($this->security->getUser()) {
            $this->add($menu, 'app_logout', label: 'Logout', icon: 'logout', translationDomain: 'routing');

            return;
        }

        $this->add($menu, 'app_login', label: 'Login', icon: 'login', translationDomain: 'routing');
        $this->add($menu, 'app_register', label: 'Register', icon: 'user-plus', translationDomain: 'routing');
    }

    #[AsEventListener(event: MenuEvent::NAVBAR_MENU)]
    public function navbarMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();

        $this->add($menu, 'app_homepage', label: 'Home', icon: 'home', translationDomain: 'routing');

        $assetsMenu = $this->addSubmenu($menu, 'Assets', icon: 'assets', translationDomain: 'routing');
        $this->add($assetsMenu, 'app_browse_assets', label: 'Browse Assets', translationDomain: 'routing');
        $this->add($assetsMenu, 'asset_search', label: 'Search Assets', translationDomain: 'routing');
        // api-grid over /api/assets.json — the listing that renders rows client-side from
        // the API payload, so it's where the inline ThumbHash blur shows up.
        $this->add($assetsMenu, 'asset_browse_grid', label: 'Assets Grid', translationDomain: 'routing');

        $recordsMenu = $this->addSubmenu($menu, 'Records', icon: 'records', translationDomain: 'routing');
        $this->add($recordsMenu, 'media_record_browse', label: 'Browse Records', translationDomain: 'routing');
        $this->add($recordsMenu, 'media_record_browse', label: 'Browse Records', translationDomain: 'routing');

        $this->add($menu, 'iiif_browse', label: 'IIIF', icon: 'iiif', translationDomain: 'routing');
        $this->add($menu, 'survos_state_workflow_dashboard', label: 'Summary', icon: 'summary', translationDomain: 'routing');
    }
}
