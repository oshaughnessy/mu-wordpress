# mu-wordpress

Run WordPress on Amazon ECS and RDS with mu

## Overview

We can use [mu](https://getmu.io) to run the official
[WordPress Docker image] (https://hub.docker.com/r/_/wordpress/) in
Amazon's EC2 Container Service, fronted by an Application Load Balancer,
backed by an RDS Aurora database, and deployed with CodeBuild and
CodePipeline. Mu does it all for you through CloudFormation using a
pair of simple YAML files.

## In brief

Clone [this repository](https://github.com/stelligent/mu-wordpress):

    cd ~/src
    git clone https://github.com/stelligent/mu-wordpress
    cd mu-wordpress

Set your AWS region if you want to use something other than the default,
`us-east-1`:

    export AWS_REGION=us-west-2

Start up your pipeline, which will deploy to 2 environments, "dev" and
"prod":

    mu pipeline up

## References:

* https://getmu.io
* https://stelligent.com/category/mu/
* https://hub.docker.com/r/_/wordpress/
* https://www.sitepoint.com/how-to-use-the-official-docker-wordpress-image/a

