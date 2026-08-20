<?php

namespace App\Controller\Admin;

use App\Entity\Asset;
use App\Entity\Media;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AssetRegistry;
use App\Workflow\AssetFlow;
use App\Workflow\AssetWorkflow;
use App\Workflow\MediaWorkflow;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AvatarField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\ComparisonType;
use Google\Service\GroupsMigration\Resource\Archive;
use Survos\EzBundle\Controller\BaseCrudController;
use Survos\MediaBundle\Service\MediaUrlGenerator;
use Survos\StateBundle\Traits\EasyMarkingTrait;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Workflow\WorkflowInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Orm\EntityRepository;

use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;

class AssetCrudController extends BaseCrudController
{
    use EasyMarkingTrait;

    public function __construct(
        #[Target(AssetFlow::WORKFLOW_NAME)] protected WorkflowInterface $workflow,
        private AssetRegistry $assetRegistry,
        private UserRepository $userRepository,
    )
    {
        parent::__construct();
    }

    public function configureFilters(Filters $filters): Filters
    {


        $users = $this->userRepository->findAll();
        $choices = [];
        foreach ($users as $user) {
            $choices[$user->getId()] = $user;
        }

        return $filters
            ->add(TextFilter::new('mime'))
//            ->add(TextFilter::new('user', 'User ID'))
//            ->add(EntityFilter::new('user', 'User'))
//                ->setFormTypeOption('value_type_options', [
//                    'class' => User::class,
//                    'choice_label' => 'id',
//                    'multiple' => false,
//                ])
//            )


//            ->add(EntityFilter::new('user', 'user')
//                ->setFormTypeOption('data_class', User::class)
//                ->setFormTypeOption('label', function (User $user) {
//                    return $user->getId(); // or any other property you want to display
//                })
//                ->canSelectMultiple(false))
            ->add($this->markingFilter())
            ;
    }


    public function XXconfigureFilters(Filters $filters): Filters
    {

        return $filters
            ->add($this->markingFilter())
            ;
//            ->add(
//                ChoiceFilter::new('user', 'User')
//                    ->setChoices($this->userChoices())   // ['forte' => 'forte', ...]
//                    ->canSelectMultiple(false)           // single scalar, not an array
//                    ->setApply(function (QueryBuilder $qb, FilterDataDto $data): void {
//                        $id = $data->getValue();
//                        if (!$id) {
//                            return;
//                        }
//                        $alias = $qb->getRootAliases()[0];
//                        // Compare on the FK without joins; works with string PKs:
//                        $qb->andWhere('IDENTITY(' . $alias . '.user) = :id')
//                            ->setParameter('id', $id);
//                    })
//            );
    }

    private function userChoices(): array
    {

        $choices = [];
        foreach ($this->userRepository->findAll() as $r) {
            $choices[$r->getId()] = $r->getId(); // label => value
        }
        return $choices;
    }


    public static function getEntityFqcn(): string
    {
        return Asset::class;
    }

    public function configureFields(string $pageName): iterable
    {


//            // only if in storage??
//        yield AvatarField::new('thumbnailUrl')
//            ->setLabel('Thumb')
//            ->formatValue(function ($value, Asset $asset) {
//                if ($asset->storageKey) {
//                    $imgProxyUrl = $this->assetRegistry->imgProxyUrl($asset, MediaUrlGenerator::PRESET_SMALL);
//                    return $imgProxyUrl;
//                }
//            })
//            ->setHelp('dynamic?')
//            ->setHeight(36);

//        yield TextField::new('thumbnailUrl')
//            ->setLabel('Thumb')
//            ->formatValue(function ($value, Asset $asset) {
//                if ($asset->storageKey) {
//                    $imgProxyUrl = $this->assetRegistry->imgProxyUrl($asset, MediaUrlGenerator::PRESET_SMALL);
//                    return "<img src='$imgProxyUrl' title='{$asset->storageKey}' style='max-width: 36px;'>";
//                }
//            })
//            ->renderAsHtml();

        yield AvatarField::new('smallUrl')->setHeight(36);  // Visual thumbnail first

//        yield TextField::new('id')
//            ->formatValue(function ($value, $entity) {
//                return sprintf(
//                    '<a href="%s">%s</a>',
//                    $this->generateUrl('admin_custom_asset', ['id' => $value]),
//                    $value
//                );
//            });

//        yield TextField::new('archiveUrl');                     // Status/workflow state
//        yield TextField::new('storageKey');                     // Status/workflow state
        yield TextField::new('marking');                     // Status/workflow state
        yield IntegerField::new('statusCode');               // HTTP status, from ORIGINAL
//        yield AssociationField::new('user');                 // Owner/user
        yield TextField::new('mime');                 // actual, from source info
//        yield TextField::new('root');                        // Storage root, now user
        yield UrlField::new('originalUrl'); // ->onlyOnDetail();                  // Source URL
//        yield ArrayField::new('resized')->onlyOnDetail();
        yield TextField::new('localCanonicalFilename')->onlyOnDetail();
        yield TextField::new('localSmallFilename')->onlyOnDetail();
        yield UrlField::new('archiveUrl')->onlyOnDetail();
        yield UrlField::new('smallUrl')->onlyOnDetail();
//        yield ArrayField::new('resizedCount', "#resized");
        yield IntegerField::new('size')->setLabel('Size (bytes)'); // File size

        // Colour fields removed with the local league/color-extractor step — they bound to a
        // `colorAnalysis` property Asset never had. imgproxy Pro's `average`/`dominant_colors`
        // live in context['info']; see docs/local-image-analysis.md.

//        yield TextEditorField::new('context')->setLabel('Context (JSON)'); // Additional data

//        yield CodeEditorField::new('context')
//            ->setLabel('Context (JSON)')
//            ->setLanguage('js')
//            ->formatValue(static function ($value) {
//                return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
//            });
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->setPermission(Action::NEW, 'ROLE_ADMIN')
            ->setPermission(Action::EDIT, 'ROLE_ADMIN')
            ->setPermission(Action::DELETE, 'ROLE_ADMIN')
            ->setPermission(Action::BATCH_DELETE, 'ROLE_ADMIN');
    }
    /*
    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id'),
            TextField::new('title'),
            TextEditorField::new('description'),
        ];
    }
    */
}
