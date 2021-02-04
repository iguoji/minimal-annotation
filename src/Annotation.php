<?php
declare(strict_types=1);

namespace Minimal\Annotation;

use Attribute;
use Reflector;
use ReflectionClass;
use ReflectionMethod;
use Minimal\Container\Container;

/**
 * 注解类
 */
class Annotation
{
    /**
     * 构造函数
     */
    public function __construct(protected Container $container)
    {
    }

    /**
     * 扫描文件夹
     */
    public function scan(string $path, array $context = []) : void
    {
        // 保存根目录
        if (!isset($context['root'])) {
            $context['root'] = $path;
        }
        // 文件夹
        if (is_dir($path)) {
            // 存在 Composer.json
            $composerJson = $path . DIRECTORY_SEPARATOR . 'composer.json';
            if (file_exists($composerJson)) {
                // 解析 Composer 文件
                $json = file_get_contents($composerJson);
                $composer = json_decode($json, true);
                // 根据应用的命名空间扫描
                if (!empty($composer) && !empty($composer['autoload']['psr-4'])) {
                    $context['namespaces'] = $composer['autoload']['psr-4'];
                    foreach ($context['namespaces'] as $namespace => $dir) {
                        $childPath = rtrim($path . DIRECTORY_SEPARATOR . $dir, DIRECTORY_SEPARATOR);
                        $this->scan($childPath, $context);
                    }
                }
            } else {
                // 根据应用的目录扫描
                $paths = glob($path . DIRECTORY_SEPARATOR . '*');
                foreach ($paths as $childPath) {
                    // 不扫描 vendor 目录
                    if ($childPath !== $path . DIRECTORY_SEPARATOR . 'vendor') {
                        $this->scan($childPath, $context);
                    }
                }
            }
        } else {
            // 保存当前目录
            $context['path'] = $path;
            // 根据路径得到类名
            $class = mb_substr($path, mb_strlen($context['root']), -4);
            $class = trim($class, DIRECTORY_SEPARATOR);
            $class = trim(mb_ereg_replace(DIRECTORY_SEPARATOR, '\\', $class));
            $class = ucwords($class);
            // 补充命名空间
            if (isset($context['namespace'])) {
                $class = $context['namespace'] . '\\' . $class;
            }
            // 解析类
            if ($class && class_exists($class)) {
                $this->parse($class, $context);
            }
        }
    }

    /**
     * 解析对象
     */
    public function parse(string $class, array $context = [])
    {
        // 全局上下文
        $context = array_merge($context, [
            'class' =>  $class,
            'target'=>  Attribute::TARGET_CLASS,
        ]);
        // 反射类
        $refClass = new ReflectionClass($class);
        // 处理类的注解
        [$context, $annoQueue] = $this->attrs($refClass, $context);
        // 循环所有公开方法
        foreach ($refClass->getMethods(ReflectionMethod::IS_PUBLIC) as $refMethod) {
            // 处理方法的注解
            $this->attrs($refMethod, array_merge($context, [
                'target'    =>  Attribute::TARGET_METHOD,
                'method'    =>  $refMethod->getName(),
            ]), $annoQueue);
        }
    }

    /**
     * 处理注解
     */
    public function attrs(Reflector $reflection, array $context, array $annoQueue = []) : array
    {
        // 循环注解
        foreach ($reflection->getAttributes() as $attr) {
            // 忽略框架或应用的内置注解类
            if ($attr->getName() == Attribute::class) {
                continue;
            }

            // 创建该对象所属类的实例
            if ($context['class'] && !isset($context['instance'])) {
                $context['instance'] = $this->container->get($context['class']);
            }

            // 注解的类名、标签、参数
            $annoClass = $attr->getName();
            $annoTag = substr($annoClass, strrpos($annoClass, '\\') + 1);
            $builtInClass = 'Minimal\\Annotations\\' . $annoTag;
            $annoTag = lcfirst($annoTag);
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

            // 将该注解加入队列，但是暂不执行，先按优先级保存到列队，待稍后一起运行
            $this->addAnnoQueue($annoQueue, $annoIns);
        }

        // 在注解队列中执行符合的目标，并得到未运行的注解实例
        $notRunAnnoIns = $this->doAnnoQueue($annoQueue, $context);

        // 返回结果
        return [$context, $notRunAnnoIns];
    }

    /**
     * 将注解加到队列
     */
    private function addAnnoQueue(array &$annoQueue, AnnotationInterface $annoIns) : void
    {
        $append = true;
        foreach ($annoQueue as $key => $item) {
            if ($item::class == $annoIns::class) {
                // 相同的注解会被替换
                $append = false;
                $annoQueue[$key] = $annoIns;
                break;
            } else if ($annoIns->getPriority() > $item->getPriority()) {
                // 优先级较大，插队
                $append = false;
                array_splice($annoQueue, $key, 0, [$annoIns]);
                break;
            }
        }
        if ($append) {
            array_push($annoQueue, $annoIns);
        }
    }

    /**
     * 执行注解队列
     */
    private function doAnnoQueue(array $annoQueue, array &$context) : array
    {
        // 在注解队列中执行符合的目标，并得到未运行的注解实例
        $notRunAnnoIns = [];
        foreach ($annoQueue as $annoIns) {
            // 注解只有放在正确位置才会执行
            if (in_array($context['target'], $annoIns->getTargets())) {
                // 执行注解功能
                $result = $annoIns->handle($context);
                // 需要将注解执行结果保存到上下文
                if (!is_null($result) && !is_null($annoIns->getContextKey())) {
                    $context[$annoIns->getContextKey()] = $result;
                }
            } else {
                // 留着下次执行吧
                $notRunAnnoIns[] = $annoIns;
            }
        }
        // 返回未执行的注解
        return $notRunAnnoIns;
    }
}