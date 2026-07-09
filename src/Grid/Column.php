<?php

namespace Kibatic\DatagridBundle\Grid;

use Symfony\Component\PropertyAccess\Exception\NoSuchPropertyException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Translation\TranslatableMessage;

class Column
{
    public string|TranslatableMessage $name;
    public $value;
    private ?string $template;
    public array|\Closure $templateParameters;
    public ?string $sortable;
    public $sortableQuery;
    public bool $enabled;
    /**
     * @var array Attributs HTML statiques du <th> de la colonne (ex. ['class' => 'num']).
     */
    public array $headerAttr;

    public function __construct(
        string|TranslatableMessage $name,
        string|callable|null $value = null,
        ?string $template = null,
        array|\Closure $templateParameters = [],
        string|array|null $sortable = null,
        callable|string|null $sortableQuery = null,
        bool $enabled = true,
        array $headerAttr = [],
    ) {
        $this->name = $name;
        $this->value = $value ?? fn($item) => $item;
        $this->template = $template;
        $this->templateParameters = $templateParameters;
        $this->sortable = $sortable;
        $this->sortableQuery = $sortableQuery;
        $this->enabled = $enabled;
        $this->headerAttr = $headerAttr;
    }

    public function getTemplate(null|object|array $entity = null): string
    {
        if ($this->template !== null) {
            return $this->template;
        }

        if ($entity !== null && is_array($this->getValue($entity))) {
            return Template::ARRAY;
        }

        return Template::TEXT;
    }

    public function getValue(object|array $entity)
    {
        if (is_array($entity)) {
            $extra = $entity;
            $entity = $entity[0];
        }

        if (!is_string($this->value) && is_callable($this->value)) {
            $valueCallback = $this->value;
            return $valueCallback($entity, $extra ?? []);
        }

        if ($this->value === null) {
            return isset($extra) ? [$entity, $extra] : $entity;
        }

        try {
            return (PropertyAccess::createPropertyAccessor())->getValue($entity, $this->value);
        } catch (NoSuchPropertyException $e) {
            if (isset($extra)) {
                return (PropertyAccess::createPropertyAccessor())->getValue($extra, "[{$this->value}]");
            }

            throw $e;
        }
    }

    /**
     * Lit un paramètre de template avec valeur par défaut. Avec l'entité de la
     * ligne, les paramètres sont résolus par ligne (closure incluse) ; sans
     * entité, une closure globale ne peut pas être résolue et le défaut est
     * renvoyé.
     */
    public function getTemplateParameter(
        string $parameterName,
        mixed $defaultValue = null,
        object|array|null $entity = null,
    ): mixed {
        if ($entity !== null) {
            return $this->getTemplateParameters($entity)[$parameterName] ?? $defaultValue;
        }

        if ($this->templateParameters instanceof \Closure) {
            return $defaultValue;
        }

        return $this->templateParameters[$parameterName] ?? $defaultValue;
    }

    /**
     * Résout les paramètres de template pour une ligne donnée. Comme pour la
     * valeur (getValue), si les paramètres sont une closure, elle est invoquée
     * avec l'entité de la ligne et retourne le tableau de paramètres ; sinon le
     * tableau statique est renvoyé tel quel.
     */
    public function getTemplateParameters(object|array $entity): array
    {
        if ($this->templateParameters instanceof \Closure) {
            if (is_array($entity)) {
                $extra = $entity;
                $entity = $entity[0];
            }

            $callback = $this->templateParameters;

            return $callback($entity, $extra ?? []);
        }

        return $this->templateParameters;
    }
}
