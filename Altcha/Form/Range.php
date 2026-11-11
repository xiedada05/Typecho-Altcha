<?php
/**
 * 滑块表单元素
 *
 * Typecho 核心未提供 range 输入, 此处补一个带实时数值展示的滑块:
 * - $options 传 ['min' => 500, 'max' => 20000, 'step' => 100];
 * - 输入框旁渲染 <output> 实时显示当前刻度 (原生 oninput, 无依赖);
 * - 回显时对非数值 (如旧版字符串配置) 回退到默认值, 避免浏览器落在非法位置。
 */

namespace TypechoPlugin\Altcha\Form;

use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Layout;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Range extends Text
{
    /**
     * 滑块边界与步长
     *
     * @var array<string, int>
     */
    private array $rangeOptions = ['min' => 0, 'max' => 100, 'step' => 1];

    /**
     * 构造时传入的默认值 (inputValue 遇到非法存量值时回退到此)
     *
     * @var mixed
     */
    private $defaultValue;

    /**
     * 数值展示元素
     */
    private ?Layout $display = null;

    /**
     * @param string|null $name    表单元素名称
     * @param array|null  $options ['min', 'max', 'step']
     * @param mixed       $value   默认值
     * @param string|null $label   表单标题
     * @param string|null $description 表单描述
     */
    public function __construct(
        ?string $name = null,
        ?array $options = null,
        $value = null,
        ?string $label = null,
        ?string $description = null
    ) {
        foreach (['min', 'max', 'step'] as $key) {
            if (isset($options[$key]) && is_numeric($options[$key])) {
                $this->rangeOptions[$key] = (int) $options[$key];
            }
        }

        $this->defaultValue = $value;

        parent::__construct($name, null, $value, $label, $description);
    }

    /**
     * @return string
     */
    protected function getType(): string
    {
        return 'range';
    }

    /**
     * 初始化滑块输入项与数值展示
     *
     * @param string|null $name 表单元素名称
     * @param array|null $options 选择项 (未使用, 边界在构造函数里取)
     * @return Layout|null
     */
    public function input(?string $name = null, ?array $options = null): ?Layout
    {
        $input = new Layout('input', [
            'id'    => $name . '-0-' . self::$uniqueId,
            'name'  => $name,
            'type'  => $this->getType(),
            'class' => 'altcha-range',
            'min'   => (string) $this->rangeOptions['min'],
            'max'   => (string) $this->rangeOptions['max'],
            'step'  => (string) $this->rangeOptions['step'],
        ]);

        $this->container($input);
        $this->inputs[] = $input;

        // 紧跟滑块之后渲染 <output>, oninput 原生联动, 免 JS 依赖
        $this->display = new Layout('output', ['class' => 'altcha-range-value']);
        $input->setAttribute('oninput', 'this.nextElementSibling.value=this.value');
        $this->container($this->display);

        if (isset($this->label)) {
            $this->label->setAttribute('for', $name . '-0-' . self::$uniqueId);
        }

        return $input;
    }

    /**
     * 设置表单元素值: 数值落入 [min, max], 非数值回退默认值
     *
     * @param mixed $value 表单元素值
     */
    protected function inputValue($value)
    {
        $normalized = $this->normalize($value);

        if (null === $normalized) {
            $this->input->removeAttribute('value');
        } else {
            $this->input->setAttribute('value', (string) $normalized);
        }

        if (null !== $this->display) {
            $this->display->html(null === $normalized ? '' : (string) $normalized);
        }
    }

    /**
     * @param mixed $value
     * @return int|null
     */
    private function normalize($value): ?int
    {
        // 旧版配置可能存的是非数值 (如预设名), 回退到默认值
        if (!is_numeric($value)) {
            $value = $this->defaultValue;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return max($this->rangeOptions['min'], min($this->rangeOptions['max'], $int));
    }
}
