<?php
/**
 * 测试专用数据库适配器: 模拟防重放表 INSERT 时的存储层错误
 *
 * 仅用于 tests/security.php, 不进入发布包。继承 Pdo_SQLite 并只拦截
 * altcha_used 的 INSERT:
 * - busy: SQLITE_BUSY ("database is locked", code 5) —— 修复前被 fail-open 放行
 * - duplicate: UNIQUE 冲突 (code 19) —— 应继续被判定为重放
 */

namespace Typecho\Db\Adapter;

class AltchaBusyAdapter extends Pdo\SQLite
{
    /** @var string busy|duplicate */
    public static string $mode = 'busy';

    public function query(
        string $query,
        $handle,
        int $op = \Typecho\Db::READ,
        ?string $action = null,
        ?string $table = null
    ): \PDOStatement {
        if (
            false !== stripos($query, 'insert into')
            && false !== stripos($query, 'altcha_used')
        ) {
            if ('duplicate' === self::$mode) {
                throw new SQLException(
                    'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: altcha_used.fingerprint',
                    19
                );
            }

            throw new SQLException('database is locked', 5);
        }

        return parent::query($query, $handle, $op, $action, $table);
    }
}
