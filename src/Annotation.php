<?php
declare(strict_types=1);

namespace Minimal\Annotation;

use Attribute;
use Reflector;
use ReflectionClass;
use ReflectionMethod;
use Psr\Container\ContainerInterface;

/**
 * 注解类
 */
class Annotation
{
    /**
     * 构造函数
     */
    public function __construct(protected ContainerInterface $container)
    {}

    /**
     * 扫描文件夹
     */
    public function scan(string $path, array $context = []) : void
    {
        // 保存根目录
        if (!isset($context['root'])) {
            $context['root'] = $path;
        }
        // 循环扫描文件夹
        if (is_dir($path)) {
            $paths = glob($path . DIRECTORY_SEPARATOR . '*');
            foreach ($paths as $childPath) {
                $this->scan($childPath, $context);
            }
        } else {
            // 根据路径得到类名
            $class = mb_substr($path, mb_strlen($context['root']), -4);
            $class = trim($class, DIRECTORY_SEPARATOR);
            $class = trim(mb_ereg_replace(DIRECTORY_SEPARATOR, '\\', $class));
            $class = ucwords($class);
            if (isset($context['namespace'])) {
                $class = $context['namespace'] . '\\' . $class;
            }
            // 解析类
            if ($class && class_exists($class)) {
                $this->parse($class, ['path' => $path]);
            }
        }
    }

    /**
     * 解析对象
     */
    public function parse(string $class, array $context = [])
    {
        // 全局上下文
        $context = array_merge([
            'class' =>  $class,
            'target'=>  Attribute::TARGET_CLASS,
        ], $context);
        // 反射类
        $refClass = new ReflectionClass($class);
        // 处理类的注解
        [$context, $queue] = $this->attrs($refClass, $context);
        // 循环所有公开方法
        foreach ($refClass->getMethods(ReflectionMethod::IS_PUBLIC) as $refMethod) {
            // 处理方法的注解
            $this->attrs($refMethod, array_merge($context, [
                'target'    =>  Attribute::TARGET_METHOD,
                'method'    =>  $refMethod->getName(),
            ]), $queue);
        }
    }

    /**
     * 处理注解
     */
    public function attrs(Reflector $reflection, array $context, array $queue = []) : array {
        // 循环注解
        foreach ($reflection->getAttributes() as $attr) {
            // 忽略注解类
            if ($attr->getName() == Attribute::class) {
                continue;
            }
            // 保存类实例
            if (isset($context['class']) && !isset($context['instance'])) {
                $context['instance'] = $this->container->make($context['class']);
            }
            // 类名和标签
            $annoClass = $attr->getName();
            $annoTag = substr($annoClass, strrpos($annoClass, '\\') + 1);
            $builtInClass = 'Minimal\\Annotations\\' . $annoTag;
            $annoTag = strtolower($annoTag);
            $annoArgs = $attr->getArguments();
            // 目的是内置注解，因为用户没有Use，所以附带了用户的命名空间
            if (!class_exists($annoClass) && class_exists($builtInClass)) {
                $annoClass = $builtInClass;
            }
            // 无效注解类，当作全局属性
            if (!class_exists($annoClass) || !in_array(AnnotationInterface::class, class_implements($annoClass))) {
                $context[$annoTag] = $annoArgs;
                continue;
            }
            // 实例化注解
            $annoIns = $this->container->make($annoClass, ...$annoArgs);
            // 按优先级保存到列队
            $append = true;
            foreach ($queue as $key => $item) {
                if ($item::class == $annoIns::class) {
                    $append = false;
                    $queue[$key] = $annoIns;
                    break;
                } else if ($annoIns->getPriority() > $item->getPriority()) {
                    $append = false;
                    array_splice($queue, $key, 0, [$annoIns]);
                    break;
                }
            }
            if ($append) {
                array_push($queue, $annoIns);
            }
        }
        // 在队列中执行符合的目标，并得到未运行的实例
        $notRunIns = [];
        foreach ($queue as $ins) {
            if (in_array($context['target'], $ins->getTargets())) {
                // 执行注解功能
                $result = $ins->handle($context);
                if (!is_null($result) && !is_null($ins->getContextKey())) {
                    $context[$ins->getContextKey()] = $result;
                }
            } else {
                $notRunIns[] = $ins;
            }
        }
        // 返回结果
        return [$context, $notRunIns];
    }
}