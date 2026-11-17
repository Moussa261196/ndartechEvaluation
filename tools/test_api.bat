@echo off
echo Testing NdarTech API...
echo.
echo === LOGIN TEST ===
curl -s -X POST "http://127.0.0.1:8888/api.php?a=login" -H "Content-Type: application/json" -H "X-Requested-With: qcm" -d "{\"identifier\":\"F_moussa\",\"password\":\"moussatech@2026\"}"
echo.
echo === TEST LEVELS LIST ===
curl -s -X POST "http://127.0.0.1:8888/api.php?a=test_levels_list" -H "Content-Type: application/json" -H "X-Requested-With: qcm" -b "PHPSESSID=ffspi79j6352j4q7s7og0a3o9q" -d "{}"
echo.
echo === TRAINER LEVELS LIST ===
curl -s -X POST "http://127.0.0.1:8888/api.php?a=trainer_levels_list" -H "Content-Type: application/json" -H "X-Requested-With: qcm" -b "PHPSESSID=ffspi79j6352j4q7s7og0a3o9q" -d "{}"
