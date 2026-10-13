"""Small internal adapter for wttr.in weather data."""
from flask import Flask, jsonify, request
from urllib.error import HTTPError, URLError
from urllib.parse import quote
from urllib.request import Request, urlopen
import json

app = Flask(__name__)


def fetch_wttr(path: str):
    url = "https://wttr.in/" + path + "?format=j1&lang=ru"
    req = Request(url, headers={"User-Agent": "WeatherClimateLab/1.0", "Accept": "application/json"})
    try:
        with urlopen(req, timeout=12) as response:
            return json.loads(response.read().decode("utf-8"))
    except HTTPError as exc:
        return {"error": True, "reason": f"wttr.in вернул HTTP {exc.code}"}, 502
    except (URLError, TimeoutError, json.JSONDecodeError) as exc:
        return {"error": True, "reason": f"Погодный сервис недоступен: {exc}"}, 502


@app.get("/health")
def health():
    return jsonify({"status": "ok", "service": "weather-adapter"})


@app.get("/search")
def search():
    location = request.args.get("location", "").strip()
    if not location:
        return jsonify({"error": True, "reason": "Не указано название города"}), 400
    result = fetch_wttr(quote(location, safe=",+-"))
    if isinstance(result, tuple):
        data, status = result
        return jsonify(data), status
    return jsonify(result)


@app.get("/weather")
def weather():
    try:
        lat = float(request.args["lat"])
        lon = float(request.args["lon"])
    except (KeyError, ValueError):
        return jsonify({"error": True, "reason": "Нужны координаты lat и lon"}), 400
    if not (-90 <= lat <= 90 and -180 <= lon <= 180):
        return jsonify({"error": True, "reason": "Координаты вне допустимого диапазона"}), 400
    result = fetch_wttr(f"{lat:.4f},{lon:.4f}")
    if isinstance(result, tuple):
        data, status = result
        return jsonify(data), status
    return jsonify(result)


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000)
