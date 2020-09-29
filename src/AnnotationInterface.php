<?php
declare(strict_types=1);

namespace iguoji;

interface AnnotationInterface
{
    /**
     * 注解处理
     */
    public function handle(array $context) : mixed;

    /**
     * 上下文的Key
     */
    public function getContextKey() : ?string;

    /**
     * 应用目标
     */
    public function getTargets() : array;

    /**
     * 优先级
     */
    public function getPriority() : int;
}