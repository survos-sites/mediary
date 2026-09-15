<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Controller\CrudControllerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Response;

/** The EasyAdmin dashboard for the CRUD controllers. Replaces meili-bundle's dashboard base. */
#[AdminDashboard('/ez', 'ez_admin')]
final class EzDashboardController extends AbstractDashboardController
{
    /** @param iterable<CrudControllerInterface> $crudControllers */
    public function __construct(
        #[AutowireIterator('ea.crud_controller')]
        private readonly iterable $crudControllers,
    ) {
    }

    public function index(): Response
    {
        return $this->redirectToRoute('app_image_search');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('Mediary Admin');
    }

    public function configureAssets(): Assets
    {
        return Assets::new()->useCustomIconSet()->addAssetMapperEntry('admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToRoute('Image search', 'tabler:photo-search', 'app_image_search');
        foreach ($this->crudControllers as $controller) {
            $class = $controller::getEntityFqcn();
            yield MenuItem::linkTo($controller::class, new \ReflectionClass($class)->getShortName(), 'tabler:database');
        }
    }
}
