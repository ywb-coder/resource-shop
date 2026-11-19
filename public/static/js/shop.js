/* Account, cart, checkout and protected downloads. */
(() => {
  const esc = (value) =>
    String(value ?? "").replace(
      /[&<>"']/g,
      (c) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#39;",
        })[c],
    );
  window.BizHelper = {
    toast(title, icon = "success") {
      return Swal.fire({
        toast: true,
        position: "top-end",
        title,
        icon,
        showConfirmButton: false,
        timer: 2400,
      });
    },
    async request(url, data = {}) {
      try {
        return await (
          await fetch(url, {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
              "X-Requested-With": "XMLHttpRequest",
              "X-CSRF-TOKEN": window.CSRF_TOKEN,
            },
            body: JSON.stringify(data),
          })
        ).json();
      } catch (error) {
        return { code: 1, msg: "请求失败，请稍后重试" };
      }
    },
    formatTime(ts) {
      return ts ? new Date(Number(ts) * 1000).toLocaleDateString("zh-CN") : "";
    },
    async confirm(title) {
      return (
        await Swal.fire({
          title,
          icon: "question",
          showCancelButton: true,
          confirmButtonText: "确定",
          cancelButtonText: "取消",
        })
      ).isConfirmed;
    },
    showCards(data) {
      const cards = (data.cards || [])
        .map(
          (c) =>
            `<div class="delivery-line"><b>${esc(c.no)}</b>${c.secret ? `<p>密码：${esc(c.secret)}</p>` : ""}</div>`,
        )
        .join("");
      const url = /^https?:\/\//i.test(data.use_url || "")
        ? `<a href="${esc(data.use_url)}" target="_blank" rel="noopener">打开使用地址</a>`
        : "";
      return Swal.fire({
        title: "获取内容",
        html:
          (cards || "<p>内容准备中，请稍后查看。</p>") +
          url +
          `<p>${esc(data.use_guide || "")}</p>`,
        confirmButtonText: "知道了",
      });
    },
  };
  window.openLogin = async (mode = "login", callback = null) => {
    const register = mode === "register";
    const result = await Swal.fire({
      title: register ? "创建账号" : "欢迎回来",
      html: `<div class="account-form"><label>手机号或邮箱<input id="account-input" autocomplete="username" placeholder="请输入账号"></label>${register ? '<label>昵称<input id="nickname-input" placeholder="怎么称呼你"></label>' : ""}<label>密码<input id="password-input" type="password" autocomplete="${register ? "new-password" : "current-password"}" placeholder="请输入密码"></label>${register ? '<p class="muted">注册即表示同意<a href="/terms" target="_blank">服务协议</a>和<a href="/privacy" target="_blank">隐私政策</a>。</p>' : ""}</div>`,
      footer: `<button class="text-button" id="switch-account-mode">${register ? "已有账号？去登录" : "第一次来？创建账号"}</button>`,
      showCancelButton: true,
      confirmButtonText: register ? "注册" : "登录",
      cancelButtonText: "稍后",
      showLoaderOnConfirm: true,
      didOpen() {
        document.getElementById("switch-account-mode").onclick = () => {
          Swal.close();
          openLogin(register ? "login" : "register", callback);
        };
      },
      async preConfirm() {
        const account = document.getElementById("account-input").value.trim(),
          password = document.getElementById("password-input").value;
        if (!account || !password) {
          Swal.showValidationMessage("请填写账号和密码");
          return false;
        }
        const r = await BizHelper.request(
          register ? "/user/register" : "/user/login",
          {
            account,
            password,
            nickname: document.getElementById("nickname-input")?.value || "",
          },
        );
        if (r.code !== 0) {
          Swal.showValidationMessage(r.msg);
          return false;
        }
        return true;
      },
    });
    if (result.isConfirmed) {
      window._isLogged = true;
      if (callback && !register) {
        const oldAccount = document.querySelector(
          ".store-actions .account-link",
        );
        if (oldAccount) {
          const link = document.createElement("a");
          link.className = "account-link";
          link.href = "/user/center";
          link.textContent = "我的资料";
          oldAccount.replaceWith(link);
        }
        await callback();
        return;
      }
      location.reload();
    }
  };
  window.openPay = async (orderNo) => {
    try {
      const r = await (
        await fetch("/pay/channels?order_no=" + encodeURIComponent(orderNo), {
          headers: { "X-Requested-With": "XMLHttpRequest" },
        })
      ).json();
      if (r.code !== 0) return BizHelper.toast(r.msg, "error");
      const channels = r.data.channels;
      if (!channels.length)
        return Swal.fire({
          title: "订单已创建",
          text: "当前暂无可用支付方式，请稍后在「我的订单」继续支付。",
          confirmButtonText: "查看订单",
        }).then(() => (location.href = "/user/orders"));
      const picked = await Swal.fire({
        title: "确认订单",
        html: `<p>${esc(r.data.order.title)}</p><strong class="checkout-price">¥${esc(r.data.order.amount)}</strong>`,
        input: "radio",
        inputOptions: Object.fromEntries(channels.map((x) => [x.type, x.name])),
        inputValue: String(channels[0].type),
        showCancelButton: true,
        confirmButtonText: "去支付",
        cancelButtonText: "稍后支付",
      });
      if (!picked.isConfirmed) return;
      const res = await BizHelper.request("/pay/submit", {
        order_no: orderNo,
        channel: Number(picked.value),
        device: /Mobile|Android|iPhone/i.test(navigator.userAgent)
          ? "h5"
          : "pc",
      });
      if (res.code === 0 && res.data.type === "redirect")
        location.href = res.data.url;
      else BizHelper.toast(res.msg || "暂时无法支付", "error");
    } catch (error) {
      BizHelper.toast("加载订单失败，请稍后重试", "error");
    }
  };
  const updateCount = (n) =>
    document
      .querySelectorAll("[data-cart-count]")
      .forEach((el) => (el.textContent = n));
  window.Cart = {
    async addItem(id) {
      if (!window._isLogged) {
        openLogin("login", () => Cart.addItem(id));
        return null;
      }
      const r = await BizHelper.request("/cart/add", { material_id: id });
      if (r.code === 0) updateCount(r.data.count);
      else BizHelper.toast(r.msg, "error");
      return r;
    },
    async removeItem(id) {
      const r = await BizHelper.request("/cart/remove", { material_id: id });
      if (r.code === 0) updateCount(r.data.count);
      return r;
    },
  };
  window.addToCartMaterial = async (id) => {
    const r = await Cart.addItem(id);
    if (r && r.code === 0) BizHelper.toast("已加入购物车");
  };
  window.openCart = async () => {
    if (!window._isLogged) return openLogin();
    try {
      const r = await (
        await fetch("/cart/items", {
          headers: { "X-Requested-With": "XMLHttpRequest" },
        })
      ).json();
      if (r.code !== 0) return BizHelper.toast(r.msg, "error");
      if (!r.data.items.length)
        return Swal.fire({
          title: "购物车是空的",
          text: "先去资料库看看，遇到需要的内容再加入。",
          confirmButtonText: "知道了",
        });
      const result = await Swal.fire({
        title: "你的购物车",
        html: r.data.items
          .map(
            (item) =>
              `<label class="cart-check"><input type="checkbox" name="cart-item" value="${item.id}" checked><span>${esc(item.title)}</span><b>¥${Number(item.price).toFixed(2)}</b><button class="text-button" type="button" data-remove="${item.id}">移除</button></label>`,
          )
          .join(""),
        showCancelButton: true,
        confirmButtonText: "合并下单",
        cancelButtonText: "继续逛逛",
        didOpen() {
          document.querySelectorAll("[data-remove]").forEach(
            (b) =>
              (b.onclick = async () => {
                await Cart.removeItem(Number(b.dataset.remove));
                b.closest("label").remove();
              }),
          );
        },
        preConfirm() {
          const ids = [
            ...document.querySelectorAll('[name="cart-item"]:checked'),
          ].map((el) => Number(el.value));
          if (!ids.length) {
            Swal.showValidationMessage("至少选择一份资料");
            return false;
          }
          return ids;
        },
      });
      if (!result.isConfirmed) return;
      const order = await BizHelper.request("/buy", {
        material_ids: result.value,
      });
      if (order.code !== 0) return BizHelper.toast(order.msg, "error");
      if (order.data.free) location.href = "/user/center";
      else openPay(order.data.order_no);
    } catch (error) {
      BizHelper.toast("购物车加载失败，请重试", "error");
    }
  };
  window.buyMaterial = async (id) => {
    if (!window._isLogged) return openLogin("login", () => buyMaterial(id));
    const r = await BizHelper.request("/buy", { material_id: id });
    if (r.code !== 0) return BizHelper.toast(r.msg, "error");
    if (r.data.free) location.href = "/user/center";
    else openPay(r.data.order_no);
  };
  window.downloadMaterial = async (id) => {
    const endpoint = "/user/download?material_id=" + id;
    try {
      const r = await (
        await fetch(endpoint, {
          headers: { "X-Requested-With": "XMLHttpRequest" },
        })
      ).json();
      if (r.code === 401) return openLogin("login", () => downloadMaterial(id));
      if (r.code !== 0) return BizHelper.toast(r.msg, "error");
      const data = r.data,
        ways = data.ways || [];
      if (ways.includes("card")) return BizHelper.showCards(data);
      if (ways.length === 1 && ways[0] === "file") {
        location.href = endpoint + "&way=file";
        return;
      }
      const pan = /^https?:\/\//i.test(data.url || "")
        ? `<a class="button" href="${esc(data.url)}" target="_blank" rel="noopener">打开网盘</a><p>提取码：${esc(data.code || "无")}</p>`
        : "";
      await Swal.fire({
        title: "获取资料",
        html:
          pan +
          (ways.includes("file")
            ? `<a class="button" href="${endpoint}&way=file">下载文件</a>`
            : ""),
        confirmButtonText: "完成",
      });
    } catch (error) {
      BizHelper.toast("下载失败，请重试", "error");
    }
  };
})();
