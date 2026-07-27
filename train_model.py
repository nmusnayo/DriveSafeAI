import pandas as pd
from sklearn.ensemble import RandomForestRegressor
import joblib

df = pd.read_csv("dataset.csv")

X = df[["ojos", "bostezos", "tiempo"]]
y = df["fatiga"]

modelo = RandomForestRegressor()
modelo.fit(X, y)

joblib.dump(modelo, "modelo_fatiga.pkl")

print("Modelo listo")