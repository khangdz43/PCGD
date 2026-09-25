<style>
    .my-navbar {
        background-color: #1e3c72;
        font-family: Arial, sans-serif;
    }

    .my-navbar ul {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
    }

    .my-navbar li {
        position: relative;
    }

    .my-navbar a {
        color: white;
        padding: 12px 20px;
        display: block;
        text-decoration: none;
        font-size: 14px;
    }

    .my-navbar a:hover {
        background-color: #0b486b;
    }

    .sub-menu {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        background-color: #2c3e50;
        min-width: 200px;
        box-shadow: 0px 4px 8px rgba(0, 0, 0, 0.3);
        z-index: 9999;
    }

    .sub-menu a {
        padding: 10px 15px;
        border-bottom: 1px solid #34495e;
    }

    .sub-menu a:hover {
        background-color: #1a252f;
    }

    .my-navbar li:hover .sub-menu {
        display: block;
    }
</style>

<div class="my-navbar">
    <ul>

        <li>
            <a href="#">Danh mục : </a>
            <div class="sub-menu">
              
                <a href="">1. Danh sách Thôn xóm</a>
                <a href="">2. Danh sách Trường</a>

            </div>
        </li>

        <li>
            <a href="#">Phiếu điều tra</a>
        </li>
    </ul>
</div>