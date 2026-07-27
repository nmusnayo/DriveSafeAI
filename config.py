import mysql.connector


def conectar_db():
    return mysql.connector.connect(
        host="localhost",
        user="root",
        password="",
        database="drivesafe_ai"
    )


def guardar_alerta(evento, nivel, fatiga, recomendacion, id_conductor=1):
    try:
        conexion = conectar_db()
        cursor = conexion.cursor()

        sql = """
            INSERT INTO alertas
            (
                id_conductor,
                evento,
                nivel,
                fatiga,
                recomendacion
            )
            VALUES (%s,%s,%s,%s,%s)
        """

        valores = (
            id_conductor,
            evento,
            nivel,
            int(fatiga),
            recomendacion
        )

        cursor.execute(sql, valores)
        conexion.commit()

        cursor.close()
        conexion.close()

        print(f"[BD] Guardado: {evento}")

    except Exception as e:
        print("Error BD:", e)


def guardar_muestra_ml(evento, nivel, fatiga, ojos, bostezos, tiempo, id_conductor=1):
    try:
        conexion = conectar_db()
        cursor = conexion.cursor()

        # Crear tabla si no existe
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS ml_samples (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_alerta INT NULL,
                id_conductor INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ojos DOUBLE NULL,
                bostezos INT NULL,
                tiempo DOUBLE NULL,
                fatiga INT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """)

        sql = """
            INSERT INTO ml_samples
            (id_conductor, ojos, bostezos, tiempo, fatiga)
            VALUES (%s,%s,%s,%s,%s)
        """

        valores = (
            id_conductor,
            float(ojos),
            int(bostezos),
            float(tiempo),
            int(fatiga)
        )

        cursor.execute(sql, valores)
        conexion.commit()

        cursor.close()
        conexion.close()

        print(f"[BD ML] Muestra guardada: ojos={ojos}, bostezos={bostezos}, tiempo={tiempo}")

    except Exception as e:
        print("Error BD ML:", e)