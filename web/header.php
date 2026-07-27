<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0b1220">
    <title>DriveSafe AI - Administracion</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
    <style>
        .user-card {
            width: 100%;
            border: 1px solid var(--glass-border);
            background: var(--surface-dark);
            color: white;
            cursor: pointer;
            padding: 16px;
            border-radius: 12px;
            display: flex;
            gap: 12px;
            align-items: center;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .user-card:hover {
            transform: translateY(-2px);
            border-color: var(--primary);
            box-shadow: 0 8px 25px rgba(8, 145, 178, 0.3);
        }

        .user-avatar {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: var(--gradient-primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 20px;
            box-shadow: 0 4px 15px rgba(8, 145, 178, 0.4);
        }

        .user-data {
            display: flex;
            flex-direction: column;
            text-align: left;
        }

        .user-data strong {
            font-size: 16px;
            font-weight: 700;
        }

        .user-data small {
            color: #94a3b8;
            font-size: 12px;
        }

        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            justify-content: center;
            align-items: center;
            z-index: 9999;
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background: var(--surface);
            width: 500px;
            max-width: 90%;
            padding: 32px;
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            animation: slideIn 0.3s ease;
        }

        .modal-header {
            display: flex;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--line);
        }

        .modal-header h2 {
            font-size: 24px;
            margin: 0;
        }

        .close-btn {
            margin-left: auto;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: none;
            background: var(--surface-strong);
            cursor: pointer;
            font-size: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .close-btn:hover {
            background: var(--danger);
            color: white;
        }

        .profile-form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 700;
            color: var(--ink);
        }

        .form-group input {
            padding: 12px 16px;
            border: 2px solid var(--line);
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.2s ease;
        }

        .form-group input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 4px rgba(8, 145, 178, 0.15);
        }

        .form-group input[readonly] {
            background: var(--surface-strong);
            color: var(--muted);
            cursor: not-allowed;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 8px;
            padding-top: 16px;
            border-top: 1px solid var(--line);
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1100;
            backdrop-filter: blur(4px);
        }

        .sidebar-overlay.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        .menu-toggle {
            display: none;
            position: fixed;
            top: 16px;
            left: 16px;
            z-index: 2000;
            padding: 12px 16px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            background: var(--gradient-primary);
            color: white;
            font-weight: 700;
            box-shadow: 0 4px 15px rgba(8, 145, 178, 0.4);
            transition: all 0.3s ease;
        }

        .menu-toggle:hover {
            transform: scale(1.05);
        }

        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: -300px;
                width: 300px;
                height: 100vh;
                z-index: 1500;
                transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                box-shadow: 0 0 50px rgba(0, 0, 0, 0.5);
            }

            .sidebar.open {
                left: 0;
            }

            .menu-toggle {
                display: block;
            }

            .content {
                margin-left: 0 !important;
                width: 100%;
                padding: 20px 16px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .modal-content {
                padding: 24px;
            }
        }
    </style>
</head>
<button class="menu-toggle" onclick="toggleSidebar()">
    ☰
</button>