""" Here goes a description """
# -*- coding: utf-8 -*-

from app import app

@app.route('/')
@app.route('/index')
def index():
    return "Hello, World!"
