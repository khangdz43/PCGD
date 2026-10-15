import os
import sys
import mysql.connector
from mysql.connector import Error

DB_HOST = "127.0.0.1"
DB_PORT = 3306
DB_USER = "root"
DB_PASSWORD = ""
DB_NAME = "pcgd"


def import_sql_file(sql_file: str):
    if not os.path.isfile(sql_file):
        raise FileNotFoundError(f"File SQL không tồn tại: {sql_file}")

    with open(sql_file, "r", encoding="utf-8") as f:
        sql = f.read()

    conn = mysql.connector.connect(
        host=DB_HOST,
        port=DB_PORT,
        user=DB_USER,
        password=DB_PASSWORD,
        database=DB_NAME,
        autocommit=False,
    )

    try:
        cursor = conn.cursor()
        cursor.execute("CREATE DATABASE IF NOT EXISTS `pcgd` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
        cursor.execute(f"USE `{DB_NAME}`")
        for result in cursor.execute(sql, multi=True):
            if result.with_rows:
                result.fetchall()
        conn.commit()
        print(f"Import SQL thành công vào database '{DB_NAME}': {sql_file}")
    except Error as e:
        conn.rollback()
        raise RuntimeError(f"Lỗi khi import SQL: {e}") from e
    finally:
        conn.close()


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python import_pcgd_sql.py <path_to_sql_file>")
        sys.exit(1)

    try:
        import_sql_file(sys.argv[1])
    except Exception as exc:
        print(f"LỖI: {exc}")
        sys.exit(1)
