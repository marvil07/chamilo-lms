<?php
/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

/**
 * Class ResourceTypeAdmin.
 */
class ResourceTypeAdmin extends AbstractAdmin
{
    protected function configureShowField(ShowMapper $showMapper)
    {
        $showMapper
            ->add('name')
        ;
    }

    protected function configureFormFields(FormMapper $formMapper)
    {
        $formMapper
            ->add('name')
            ->end()
        ;
    }

//    /**
//     * @param DatagridMapper $datagridMapper
//     */
//    protected function configureDatagridFilters(DatagridMapper $datagridMapper)
//    {
//        $datagridMapper
//            ->add('url')
//        ;
//    }
//

    protected function configureListFields(ListMapper $listMapper)
    {
        $listMapper
            ->addIdentifier('name')
        ;
    }
}
