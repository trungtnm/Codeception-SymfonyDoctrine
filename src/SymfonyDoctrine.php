<?php

namespace Trungtnm\Codeception\Module;

use Codeception\Exception\ModuleConfigException;
use Codeception\Exception\ModuleException;
use Codeception\Module as CodeceptionModule;
use Codeception\Lib\Interfaces\DataMapper;
use Codeception\Lib\Interfaces\DependsOnModule;
use Codeception\Module\Symfony;
use Codeception\TestInterface;
use Codeception\Util\Stub;

class SymfonyDoctrine extends CodeceptionModule implements DependsOnModule, DataMapper
{
    /**
     * Allows integration and testing for projects with Doctrine2 ORM.
     * Doctrine2 uses EntityManager to perform all database operations.
     *
     * ```
     * modules:
     *     enabled:
     *         - Symfony
     *         - SymfonyDoctrine:
     *             depends: Symfony
     *             entity_managers: ['default', 'custom']
     * ```
     */

    protected $config = [
        'cleanup' => true,
        'depends' => 'Symfony',
        'entity_managers' => [
            'default'
        ]
    ];

    protected $dependencyMessage = <<<EOF
    enabled:
        - SymfonyDoctrine:
            depends: Symfony
EOF;

    /**
     * @var array
     */
    public $ems = [];

    /**
     * @var Symfony
     */
    private $symfony;

    public function _depends()
    {
        return ['Codeception\Module\Symfony' => $this->dependencyMessage];
    }

    public function _inject(Symfony $dependentModule = null)
    {
        $this->symfony = $dependentModule;
    }

    public function _beforeSuite($settings = [])
    {
        $this->retrieveEntityManager();
    }

    public function _before(TestInterface $test)
    {
        $this->retrieveEntityManager();
        if ($this->config['cleanup']) {
            foreach ($this->ems as $em)
            $em->getConnection()->beginTransaction();
        }
    }

    protected function retrieveEntityManager()
    {
        if (!$this->symfony) {
            throw new ModuleConfigException(
                __CLASS__,
                "This module depends on Module Symfony to work properly"
            );
        }
        $doctrine = $this->symfony->grabService('doctrine');
        foreach ($this->config['entity_managers'] as $connection) {
            $entityManager = $doctrine->getManager($connection);
            if (!($entityManager instanceof \Doctrine\ORM\EntityManagerInterface)) {
                throw new ModuleConfigException(
                    __CLASS__,
                    "Connection object is not an instance of \\Doctrine\\ORM\\EntityManagerInterface.\n"
                    . "Please check your entity_managers` config option to specify correct Entity Managers"
                );
            }
            $this->ems[$connection] = $entityManager;
            $this->ems[$connection]->getConnection()->connect();
        }
    }

    public function _after(TestInterface $test)
    {
        foreach ($this->ems as $em) {
            if (!$em instanceof \Doctrine\ORM\EntityManagerInterface) {
                return;
            }
            if ($this->config['cleanup'] && $em->getConnection()->isTransactionActive()) {
                try {
                    $em->getConnection()->rollback();
                } catch (\PDOException $e) {
                }
            }
            $em->getConnection()->close();
            $this->clean($em);
        }
    }

    /**
     * @param $em \Doctrine\ORM\EntityManagerInterface
     */
    protected function clean($em)
    {
        $reflectedEm = new \ReflectionClass($em);
        if ($reflectedEm->hasProperty('repositories')) {
            $property = $reflectedEm->getProperty('repositories');
            $property->setAccessible(true);
            $property->setValue($em, []);
        }
        $em->clear();
    }


    /**
     * Performs $em->flush();
     *
     * @param string $connection
     */
    public function flushToDatabase($connection = '')
    {
        $em = $this->_getEntityManager($connection);
        $em->flush();
    }


    /**
     * Adds entity to repository and flushes. You can redefine it's properties with the second parameter.
     * @param        $obj
     * @param array  $values
     * @param string $connection
     */
    public function persistEntity($obj, $values = [], $connection = '')
    {
        if ($values) {
            $reflectedObj = new \ReflectionClass($obj);
            foreach ($values as $key => $val) {
                $property = $reflectedObj->getProperty($key);
                $property->setAccessible(true);
                $property->setValue($obj, $val);
            }
        }
        $em = $this->_getEntityManager($connection);
        $em->persist($obj);
        $em->flush();
    }

    /**
     * Mocks the repository.
     *
     * @param        $classname
     * @param array  $methods
     * @param string $connection
     */
    public function haveFakeRepository($classname, $methods = [], $connection = '')
    {
        $em = $this->_getEntityManager($connection);

        $metadata = $em->getMetadataFactory()->getMetadataFor($classname);
        $customRepositoryClassName = $metadata->customRepositoryClassName;

        if (!$customRepositoryClassName) {
            $customRepositoryClassName = '\Doctrine\ORM\EntityRepository';
        }

        $mock = Stub::make(
            $customRepositoryClassName,
            array_merge(
                [
                    '_entityName' => $metadata->name,
                    '_em' => $em,
                    '_class' => $metadata
                ],
                $methods
            )
        );
        $em->clear();
        $reflectedEm = new \ReflectionClass($em);
        if ($reflectedEm->hasProperty('repositories')) {
            $property = $reflectedEm->getProperty('repositories');
            $property->setAccessible(true);
            $property->setValue($em, array_merge($property->getValue($em), [$classname => $mock]));
        } else {
            $this->debugSection(
                'Warning',
                'Repository can\'t be mocked, the EventManager class doesn\'t have "repositories" property'
            );
        }
    }

    /**
     * Persists record into repository.
     * This method crates an entity, and sets its properties directly (via reflection).
     * Setters of entity won't be executed, but you can create almost any entity and save it to database.
     * Returns id using `getId` of newly created entity.
     *
     * @param        $entity
     * @param array  $data
     * @param string $connection
     *
     * @return
     */
    public function haveInRepository($entity, array $data, $connection = '')
    {
        $em = $this->_getEntityManager($connection);
        $reflectedEntity = new \ReflectionClass($entity);
        $entityObject = $reflectedEntity->newInstance();
        foreach ($reflectedEntity->getProperties() as $property) {
            /** @var $property \ReflectionProperty */
            if (!isset($data[$property->name])) {
                continue;
            }
            $property->setAccessible(true);
            $property->setValue($entityObject, $data[$property->name]);
        }
        $em->persist($entityObject);
        $em->flush();

        if (method_exists($entityObject, 'getId')) {
            $id = $entityObject->getId();
            $this->debug("$entity entity created with id:$id");
            return $id;
        }
    }

    /**
     * Flushes changes to database executes a query defined by array.
     * It builds query based on array of parameters.
     * You can use entity associations to build complex queries.
     *
     * Fails if record for given criteria can\'t be found,
     *
     * @param        $entity
     * @param array  $params
     * @param string $connection
     */
    public function seeInRepository($entity, $params = [], $connection = '')
    {
        $res = $this->proceedSeeInRepository($entity, $params, $connection);
        $this->assert($res);
    }

    /**
     * Flushes changes to database and performs ->findOneBy() call for current repository.
     *
     * @param        $entity
     * @param array  $params
     * @param string $connection
     */
    public function dontSeeInRepository($entity, $params = [], $connection = '')
    {
        $res = $this->proceedSeeInRepository($entity, $params, $connection);
        $this->assertNot($res);
    }

    protected function proceedSeeInRepository($entity, $params = [], $connection = '')
    {
        $em = $this->_getEntityManager($connection);
        // we need to store to database...
        $em->flush();
        $data = $em->getClassMetadata($entity);
        $qb = $em->getRepository($entity)->createQueryBuilder('s');
        $this->buildAssociationQuery($qb, $entity, 's', $params, $connection);
        $this->debug($qb->getDQL());
        $res = $qb->getQuery()->getArrayResult();

        return ['True', (count($res) > 0), "$entity with " . json_encode($params)];
    }

    /**
     * Selects field value from repository.
     * It builds query based on array of parameters.
     * You can use entity associations to build complex queries.
     * @version 1.1
     *
     * @param        $entity
     * @param        $field
     * @param array  $params
     * @param string $connection
     *
     * @return array
     */
    public function grabFromRepository($entity, $field, $params = [], $connection = '')
    {
        $em = $this->_getEntityManager($connection);
        // we need to store to database...
        $em->flush();
        $data = $em->getClassMetadata($entity);
        $qb = $em->getRepository($entity)->createQueryBuilder('s');
        $qb->select('s.' . $field);
        $this->buildAssociationQuery($qb, $entity, 's', $params, $connection);
        $this->debug($qb->getDQL());
        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param        $qb
     * @param        $assoc
     * @param        $alias
     * @param        $params
     * @param string $connection
     */
    protected function buildAssociationQuery($qb, $assoc, $alias, $params, $connection = '')
    {
        $em = $this->_getEntityManager($connection);
        $data = $em->getClassMetadata($assoc);
        foreach ($params as $key => $val) {
            if (isset($data->associationMappings)) {
                if ($map = array_key_exists($key, $data->associationMappings)) {
                    if (is_array($val)) {
                        $qb->innerJoin("$alias.$key", $key);
                        foreach ($val as $column => $v) {
                            if (is_array($v)) {
                                $this->buildAssociationQuery($qb, $map['targetEntity'], $column, $v, $connection);
                                continue;
                            }
                            $paramname = $key . '__' . $column;
                            $qb->andWhere("$key.$column = :$paramname");
                            $qb->setParameter($paramname, $v);
                        }
                        continue;
                    }
                }
            }
            if ($val === null) {
                $qb->andWhere("s.$key IS NULL");
            } else {
                $paramname = str_replace(".", "", "s_$key");
                $qb->andWhere("s.$key = :$paramname");
                $qb->setParameter($paramname, $val);
            }
        }
    }

    public function _getEntityManager($connection = '')
    {
        $connection = $connection ? $connection : 'default';
        if (!in_array($connection, $this->config['entity_managers'])) {
            throw new ModuleException(
                __CLASS__,
                "Invalid entity manager `$connection`, check your `entity_managers` configuration first"
            );
        }

        return $this->ems[$connection];
    }
}